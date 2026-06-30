<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job che processa UNA scheda (record) per la traduzione IT → EN.
 *
 * Legge i testi da i18n_texts (lang=it) per entity_type in ('record', 'place', 'agent'):
 * - record: entity_id = record_id della scheda
 * - place/agent: entity_id delle entità collegate alla scheda tramite record_places/record_agents.
 * Invia i testi a Libre Translate, inserisce/aggiorna le righe tradotte (lang=en) con lo stesso
 * entity_type e imposta records.is_translated = true.
 *
 * Casi particolari (il campo non viene tradotto; si crea/aggiorna lang='en' copiando il valore IT):
 * - text_value (IT) è una stringa tutta maiuscola;
 * - field_name = 'title';
 * - text_value (IT) è puramente numerico (es. "42", "3.14", dopo trim).
 *
 * Esegue una sola scheda per invocazione; lo scheduler richiama il job periodicamente.
 */
class TranslateRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Chiave cache che abilita l’invio di nuovi job da parte dello scheduler. */
    public const CACHE_KEY_ENABLED = 'translation_worker_enabled';

    private const MASTER_SCHEMA = 'iartnet_master';

    public int $tries = 1;

    public int $timeout = 150;

    public function handle(): void
    {
        if (! Cache::get(self::CACHE_KEY_ENABLED, false)) {
            Log::debug('TranslateRecordJob: Worker disabled, skipping');

            return;
        }

        $url = config('services.libre_translate.url');
        if ($url === '') {
            Log::warning('TranslateRecordJob: LIBRE_TRANSLATE_URL not set');

            return;
        }

        $translateUrl = $url.'/translate';
        if (str_contains($url, '/translate')) {
            $translateUrl = $url;
        }

        $recordId = null;

        try {
            $keys = [];
            $originalValues = [];
            $valuesToTranslate = [];
            $indicesToTranslate = [];
            $hadItalianTexts = false;
            $buildTranslationArraysMs = null;

            // Prima transazione: selezione riga (FOR UPDATE SKIP LOCKED), caricamento testi, preparazione input — niente HTTP.
            DB::transaction(function () use (&$recordId, &$keys, &$originalValues, &$valuesToTranslate, &$indicesToTranslate, &$hadItalianTexts, &$buildTranslationArraysMs) {
                $record = $this->pickOneRecordToTranslate();
                if ($record === null) {
                    return;
                }
                $recordId = $record->id;

                $texts = $this->loadItalianTextsForRecord($recordId);
                if (empty($texts)) {
                    $this->markRecordAsTranslated($recordId);

                    return;
                }

                $hadItalianTexts = true;

                // Misura solo la costruzione in memoria di chiavi e array da inviare a LibreTranslate (escluso load DB sopra).
                $buildArraysStartedAt = microtime(true);

                // Chiavi (entity_type, entity_id, field_name) e valori IT in ordine identico
                $keys = [];
                $originalValues = [];
                foreach ($texts as $row) {
                    $keys[] = [
                        'entity_type' => $row->entity_type,
                        'entity_id' => $row->entity_id,
                        'field_name' => $row->field_name,
                    ];
                    $originalValues[] = $row->text_value ?? '';
                }

                // Casi particolari: non tradurre, usare valore IT per lang='en'.
                // - testo tutto maiuscolo; - field_name = 'title'; - valore numerico (copia identica in EN).
                $valuesToTranslate = [];
                $indicesToTranslate = [];
                foreach ($originalValues as $i => $value) {
                    $fieldName = $keys[$i]['field_name'] ?? '';
                    $asString = (string) $value;
                    if (
                        $this->isAllUppercase($asString)
                        || $fieldName === 'title'
                        || $this->isNumericTextValue($asString)
                    ) {
                        continue;
                    }
                    $indicesToTranslate[] = $i;
                    $valuesToTranslate[] = $value;
                }

                $buildTranslationArraysMs = $this->elapsedMillisecondsSince($buildArraysStartedAt);
            });

            if ($recordId === null) {
                Log::debug('TranslateRecordJob: No record to translate');

                return;
            }

            // Nessun testo IT: già marcato nella prima transazione.
            if (! $hadItalianTexts) {
                Log::info('TranslateRecordJob: Record translated', ['record_id' => $recordId]);

                return;
            }

            // HTTP fuori da qualsiasi transazione (riduce tempo di lock sul DB).
            $translated = [];
            $libreTranslateMs = 0.0;
            if ($valuesToTranslate !== []) {
                $libreStartedAt = microtime(true);
                $response = Http::timeout(120)->post($translateUrl, [
                    'q' => $valuesToTranslate,
                    'source' => 'it',
                    'target' => 'en',
                    'format' => 'text',
                ]);

                if (! $response->successful()) {
                    Log::error('TranslateRecordJob: Libre Translate request failed', [
                        'record_id' => $recordId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'libre_translate_ms' => $this->elapsedMillisecondsSince($libreStartedAt),
                    ]);
                    throw new \RuntimeException('Libre Translate request failed: '.$response->status());
                }

                $translated = $response->json('translatedText');
                if (! is_array($translated)) {
                    $translated = $response->json();
                    if (is_string($translated)) {
                        $translated = [$translated];
                    }
                }
                if (! is_array($translated) || count($translated) !== count($valuesToTranslate)) {
                    Log::error('TranslateRecordJob: Unexpected response shape', [
                        'record_id' => $recordId,
                        'response' => $response->body(),
                        'libre_translate_ms' => $this->elapsedMillisecondsSince($libreStartedAt),
                    ]);
                    throw new \RuntimeException('Unexpected translation response');
                }

                $libreTranslateMs = $this->elapsedMillisecondsSince($libreStartedAt);
            }

            // Valore finale per lang=en: originale se tutto maiuscolo / title, altrimenti tradotto
            $enValues = $this->buildEnValuesForKeys($originalValues, $indicesToTranslate, $translated);

            $schema = self::MASTER_SCHEMA;
            $appliedWrite = false;

            // Seconda transazione: solo scrittura; evita sovrascrittura se nel frattempo un altro worker ha completato.
            $dbWriteStartedAt = microtime(true);
            DB::transaction(function () use ($recordId, $keys, $originalValues, $enValues, $schema, &$appliedWrite) {
                $current = DB::selectOne(
                    "SELECT id, is_translated FROM \"{$schema}\".records WHERE id = ?::uuid FOR UPDATE",
                    [$recordId]
                );
                if ($current === null) {
                    return;
                }
                $flag = $current->is_translated;
                $alreadyTranslated = $flag === true || $flag === 't' || $flag === 1
                    || $flag === 'true' || $flag === '1';
                if ($alreadyTranslated) {
                    return;
                }

                $now = now();
                $rows = [];
                foreach ($keys as $i => $key) {
                    $rows[] = [
                        'entity_type' => $key['entity_type'],
                        'entity_id' => $key['entity_id'],
                        'field_name' => $key['field_name'],
                        'lang' => 'en',
                        'text_value' => $enValues[$i] ?? $originalValues[$i] ?? '',
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table("{$schema}.i18n_texts")->upsert(
                        $rows,
                        ['entity_type', 'entity_id', 'field_name', 'lang'],
                        ['text_value', 'updated_at', 'created_at']
                    );
                    /*
                     * INSERT (alternativa): inserisce sempre nuove righe; fallisce in caso di
                     * chiave univoca (entity_type, entity_id, field_name, lang) già presente.
                     *
                     * DB::table("{$schema}.i18n_texts")->insert($rows);
                     */
                }

                $this->markRecordAsTranslated($recordId);
                $appliedWrite = true;
            });
            $dbWriteMs = $this->elapsedMillisecondsSince($dbWriteStartedAt);

            $timingContext = [
                'record_id' => $recordId,
                'timing_ms' => [
                    'build_translation_arrays' => $buildTranslationArraysMs,
                    'libre_translate' => $libreTranslateMs,
                    'db_write' => $dbWriteMs,
                ],
                'counts' => [
                    'i18n_it_rows' => count($keys),
                    'strings_sent_to_libre' => count($valuesToTranslate),
                ],
                'applied_write' => $appliedWrite,
            ];

            if ($appliedWrite) {
                Log::info('TranslateRecordJob: Record translated (phase timings)', $timingContext);
            } else {
                Log::debug('TranslateRecordJob: Write skipped (phase timings)', $timingContext);
            }
        } catch (\Throwable $e) {
            Log::error('TranslateRecordJob: Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Seleziona un solo record con is_translated = false, bloccandolo (FOR UPDATE SKIP LOCKED).
     */
    private function pickOneRecordToTranslate(): ?object
    {
        $schema = self::MASTER_SCHEMA;
        $row = DB::selectOne(
            "SELECT id FROM \"{$schema}\".records 
             WHERE is_translated = false 
             LIMIT 1 
             FOR UPDATE SKIP LOCKED",
            []
        );

        return $row;
    }

    /**
     * Carica tutti i testi in italiano (lang=it) per la scheda: record, place e agent collegati.
     *
     * Include:
     * - entity_type='record', entity_id=record_id
     * - entity_type='place', entity_id in record_places per questo record
     * - entity_type='agent', entity_id in record_agents per questo record
     *
     * @return list<object{entity_type: string, entity_id: string, field_name: string, text_value: string}>
     */
    private function loadItalianTextsForRecord(string $recordId): array
    {
        $schema = self::MASTER_SCHEMA;
        $rows = DB::select(
            "SELECT entity_type, entity_id::text AS entity_id, field_name, text_value 
             FROM \"{$schema}\".i18n_texts 
             WHERE lang = 'it'
             AND (
               (entity_type = 'record' AND entity_id = ?::uuid)
               OR (entity_type = 'place' AND entity_id IN (SELECT place_id FROM \"{$schema}\".record_places WHERE record_id = ?::uuid))
               OR (entity_type = 'agent' AND entity_id IN (SELECT agent_id FROM \"{$schema}\".record_agents WHERE record_id = ?::uuid))
             )",
            [$recordId, $recordId, $recordId]
        );

        return $rows;
    }

    /**
     * Verifica se la stringa è tutta maiuscola (nessuna lettera minuscola).
     * In tal caso il campo non viene tradotto e si copia il valore IT in lang=en.
     */
    private function isAllUppercase(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return $trimmed === mb_strtoupper($trimmed, 'UTF-8');
    }

    /**
     * True se, dopo trim, la stringa rappresenta un numero accettato da {@see is_numeric()}
     * (interi, decimali, segno, notazione scientifica PHP).
     * Il campo non viene inviato a LibreTranslate: in lang=en si copia lo stesso valore del campo IT.
     */
    private function isNumericTextValue(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return is_numeric($trimmed);
    }

    /**
     * Costruisce l'array dei valori per lang=en: per gli indici in indicesToTranslate
     * usa il corrispondente valore tradotto; per gli altri (maiuscolo, title, numerico, ecc.) l'originale.
     *
     * @param  array<int, string>  $originalValues  Valori IT in ordine delle chiavi
     * @param  array<int, int>  $indicesToTranslate  Indici delle chiavi inviate a Libre Translate
     * @param  array<int, string>  $translated  Risposta dell'API (stesso ordine di indicesToTranslate)
     * @return array<int, string>
     */
    private function buildEnValuesForKeys(array $originalValues, array $indicesToTranslate, array $translated): array
    {
        $enValues = [];
        $translatedIndex = 0;
        foreach ($originalValues as $i => $original) {
            if (in_array($i, $indicesToTranslate, true)) {
                $enValues[$i] = $translated[$translatedIndex] ?? $original;
                $translatedIndex++;
            } else {
                $enValues[$i] = $original;
            }
        }

        return $enValues;
    }

    private function markRecordAsTranslated(string $recordId): void
    {
        $schema = self::MASTER_SCHEMA;
        DB::update(
            "UPDATE \"{$schema}\".records SET is_translated = true, updated_at = ? WHERE id = ?::uuid",
            [now(), $recordId]
        );
    }

    /**
     * Millisecondi trascorsi da un timestamp ottenuto con microtime(true).
     */
    private function elapsedMillisecondsSince(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
