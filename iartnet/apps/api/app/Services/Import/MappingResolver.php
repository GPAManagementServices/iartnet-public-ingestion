<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * MappingResolver
 *
 * Interpreta il file di mapping YAML e risolve i valori dal Mirror KV.
 * Supporta campi singoli e multipli, trasformazioni e validazioni.
 */
class MappingResolver
{
    /**
     * Path del file di mapping.
     */
    private string $mappingFilePath;

    /**
     * Array del mapping caricato.
     *
     * @var array<string, mixed>
     */
    private array $mapping = [];

    /**
     * Configurazione globale dal file di mapping.
     *
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * Costruttore.
     *
     * @param  string  $mappingFilePath  Path relativo o assoluto al file di mapping YAML
     */
    public function __construct(string $mappingFilePath)
    {
        $this->mappingFilePath = $mappingFilePath;
        $this->loadMapping();
    }

    /**
     * Carica il file di mapping YAML.
     *
     * Implementa la logica di fallback: cerca prima in storage/mapping/local/,
     * se non trovato cerca in storage/mapping/base/.
     *
     * @return void
     *
     * @throws \RuntimeException Se il file non esiste o non è valido
     */
    private function loadMapping(): void
    {
        // Prima cerca nel folder local/
        $localPath = storage_path('mapping/local/'.$this->mappingFilePath);
        $basePath = storage_path('mapping/base/'.$this->mappingFilePath);
        
        // Determina il percorso del file da utilizzare
        $fullPath = null;
        if (file_exists($localPath)) {
            $fullPath = $localPath;
            Log::debug('MappingResolver: Using local mapping file', ['path' => $localPath]);
        } elseif (file_exists($basePath)) {
            $fullPath = $basePath;
            Log::debug('MappingResolver: Using base mapping file', ['path' => $basePath]);
        }

        if ($fullPath === null) {
            throw new \RuntimeException(
                "Mapping file not found in local or base directories. ".
                "Searched: {$localPath} and {$basePath}"
            );
        }

        try {
            $content = file_get_contents($fullPath);
            $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

            if ($extension === 'yaml' || $extension === 'yml') {
                $parsed = $this->parseYaml($content);
            } elseif ($extension === 'json') {
                $parsed = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Invalid JSON: '.json_last_error_msg());
                }
            } else {
                throw new \RuntimeException("Unsupported file format: {$extension}");
            }

            $this->mapping = $parsed['mappings'] ?? [];
            $this->config = $parsed['config'] ?? [];

            if (empty($this->mapping)) {
                Log::warning('MappingResolver: mapping array is empty', ['file' => $fullPath]);
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to parse mapping file: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Risolve i valori per un record dal Mirror KV.
     *
     * @param  array<int, array<string, mixed>>  $kvPairs  Array di KV pairs dal Mirror (xpath => value)
     * @return array<string, array<int, array<string, mixed>>>  Array strutturato per tabella: [table_name => [mappings]]
     */
    public function resolve(array $kvPairs): array
    {
        $resolved = [];

        // Crea un indice per lookup veloce: xpath => [values]
        $kvIndex = $this->buildKvIndex($kvPairs);

        foreach ($this->mapping as $mappingRule) {
            $sourceField = $mappingRule['source_field'] ?? null;
            $targetTable = $mappingRule['target_table'] ?? null;
            $targetField = $mappingRule['target_field'] ?? null;
            $multiple = $mappingRule['multiple'] ?? false;
            $required = $mappingRule['required'] ?? false;
            $transform = $mappingRule['transform'] ?? null;

            if (!$sourceField || !$targetTable || !$targetField) {
                Log::warning('MappingResolver: invalid mapping rule', ['rule' => $mappingRule]);
                continue;
            }

            // Cerca il valore nel KV index
            $values = $kvIndex[$sourceField] ?? [];

            // Log per debug: verifica se il campo esiste nel KV index
            if (empty($values)) {
                Log::debug('MappingResolver: Field not found in KV index', [
                    'source_field' => $sourceField,
                    'target_table' => $targetTable,
                    'target_field' => $targetField,
                    'required' => $required,
                    'available_xpaths' => array_slice(array_keys($kvIndex), 0, 10), // Prime 10 xpath disponibili
                ]);
            }

            // Per measurements_merge, il transform legge direttamente i sotto-campi,
            // quindi non serve che MT/MIS esista nel KV index
            $isMeasurementsMerge = ($transform === 'measurements_merge');

            if (empty($values) && $required && !$isMeasurementsMerge) {
                Log::warning('MappingResolver: required field missing', [
                    'source_field' => $sourceField,
                    'target_table' => $targetTable,
                    'target_field' => $targetField,
                ]);
                // TODO: Gestire secondo config.on_missing_field
                continue;
            }

            if (empty($values) && !$required && !$isMeasurementsMerge) {
                // Campo opzionale mancante, skip (tranne per measurements_merge)
                continue;
            }

            // Applica trasformazione se presente
            // Per measurements_merge, passiamo l'intero kvIndex per accedere a tutti i sotto-campi
            if ($transform) {
                if ($transform === 'measurements_merge') {
                    $values = $this->transformMeasurementsMerge($kvIndex, $mappingRule);
                    // Se measurements_merge restituisce array vuoto, skip
                    if (empty($values)) {
                        continue;
                    }
                } else {
                    $values = $this->applyTransform($values, $transform, $mappingRule);
                }
            }

            // Se non è multiplo, prendi solo il primo valore
            if (!$multiple && !empty($values)) {
                $values = [$values[0]];
            }

            // Aggiungi al risultato strutturato per tabella
            if (!isset($resolved[$targetTable])) {
                $resolved[$targetTable] = [];
            }

            foreach ($values as $value) {
                $resolved[$targetTable][] = [
                    'field' => $targetField,
                    'value' => $value,
                    'source_field' => $sourceField,
                    'mapping_rule' => $mappingRule,
                    'kv_key' => $mappingRule['kv_key'] ?? null, // Nome DC/EDM per record_kv.key
                ];
            }
        }

        // Estensione ICCD/SBN: TUTTI i campi mirror (xpath) → default record_kv (kv_key = xpath).
        // Così importiamo tutti i campi originali con XPath come key; i campi definiti nello yaml
        // sono inoltre generati dal loop sopra (mapping esplicito).
        if ($this->shouldResolveUnmappedAsRecordKv() && ! empty($kvIndex)) {
            $defaultRecordKv = $this->resolveAllKvAsRecordKv($kvIndex);
            foreach ($defaultRecordKv as $item) {
                if (! isset($resolved['record_kv'])) {
                    $resolved['record_kv'] = [];
                }
                $resolved['record_kv'][] = $item;
            }
        }

        // card_type fisso per SBN e JSON: un campo record_kv in più con kv_key "card_type".
        $cardTypeEntry = $this->getCardTypeRecordKvEntry();
        if ($cardTypeEntry !== null) {
            if (! isset($resolved['record_kv'])) {
                $resolved['record_kv'] = [];
            }
            $resolved['record_kv'][] = $cardTypeEntry;
        }

        return $resolved;
    }

    /**
     * Indica se il file di mapping è iccd-to-master.yaml (import Mirror ICCD → Master).
     */
    private function isIccdToMasterMapping(): bool
    {
        return str_contains($this->mappingFilePath, 'iccd-to-master');
    }

    /**
     * Restituisce l'entry record_kv per card_type (kv_key = "card_type") solo per SBN e JSON.
     * - sbn-to-master.yaml: valore 'SBN'
     * - json-to-master.yaml: valore 'JSON'
     * - iccd-to-master, added-fields, ecc.: null — nessuna entry aggiuntiva; ICCD resta solo sul mapping YAML.
     *
     * @return array<string, mixed>|null
     */
    private function getCardTypeRecordKvEntry(): ?array
    {
        $base = strtolower(basename($this->mappingFilePath));
        $value = match (true) {
            in_array($base, ['sbn-to-master.yaml', 'sbn-to-master.yml'], true) => 'SBN',
            in_array($base, ['json-to-master.yaml', 'json-to-master.yml'], true) => 'JSON',
            default => null,
        };
        if ($value === null) {
            return null;
        }
        $rule = [
            'target_table' => 'record_kv',
            'target_field' => 'value_text',
            'kv_key' => 'card_type',
            'multiple' => false,
            'transform' => 'string',
            'lang' => 'it',
        ];

        return [
            'field' => 'value_text',
            'value' => $value,
            'source_field' => 'card_type',
            'mapping_rule' => $rule,
            'kv_key' => 'card_type',
        ];
    }

    /**
     * Indica se i campi mirror non mappati devono essere importati come record_kv con kv_key = xpath.
     * Vale per iccd-to-master e sbn-to-master.
     */
    private function shouldResolveUnmappedAsRecordKv(): bool
    {
        return $this->isIccdToMasterMapping() || str_contains($this->mappingFilePath, 'sbn-to-master');
    }

    /**
     * Restituisce l'insieme degli source_field presenti nel mapping (per escluderli dal default).
     *
     * @return array<string, true>
     */
    private function getMappedSourceFields(): array
    {
        $out = [];
        foreach ($this->mapping as $mappingRule) {
            $sf = $mappingRule['source_field'] ?? null;
            if ($sf !== null && $sf !== '') {
                $out[$sf] = true;
            }
        }

        return $out;
    }

    /**
     * Per ogni xpath in kvIndex produce una entry record_kv con definizione default (XPath come key).
     * Importa così TUTTI i campi ICCD sorgente in record_kv; i campi definiti nello yaml
     * sono inoltre generati dal mapping esplicito nel resolve().
     * Default: target_table record_kv, target_field value_text, kv_key = xpath, multiple false, transform string, lang it.
     *
     * @param  array<string, array<int, mixed>>  $kvIndex
     * @return list<array<string, mixed>>
     */
    private function resolveAllKvAsRecordKv(array $kvIndex): array
    {
        $defaultRule = [
            'target_table' => 'record_kv',
            'target_field' => 'value_text',
            'kv_key' => null, // impostato per ogni xpath
            'multiple' => false,
            'transform' => 'string',
            'lang' => 'it',
        ];

        $items = [];
        foreach ($kvIndex as $xpath => $values) {
            $values = array_filter($values, fn ($v) => $v !== null && (string) $v !== '');
            if (empty($values)) {
                continue;
            }
            $value = $values[0]; // multiple: false
            $xpathString = (string) $xpath; // Mirror può restituire xpath numerici (es. 001)
            $rule = $defaultRule;
            $rule['kv_key'] = $xpathString;
            $items[] = [
                'field' => 'value_text',
                'value' => $value,
                'source_field' => $xpathString,
                'mapping_rule' => $rule,
                'kv_key' => $xpathString,
            ];
        }

        return $items;
    }

    /**
     * Costruisce un indice KV per lookup veloce.
     *
     * @param  array<int, array<string, mixed>>  $kvPairs  Array di KV pairs
     * @return array<string, array<int, mixed>>  Indice: xpath => [values]
     */
    private function buildKvIndex(array $kvPairs): array
    {
        $index = [];

        foreach ($kvPairs as $pair) {
            $xpath = $pair['xpath'] ?? null;
            $value = $pair['value_text'] ?? null;

            if ($xpath === null || $value === null) {
                continue;
            }

            if (!isset($index[$xpath])) {
                $index[$xpath] = [];
            }

            $index[$xpath][] = $value;
        }

        return $index;
    }

    /**
     * Applica una trasformazione ai valori.
     *
     * @param  array<int, mixed>  $values  Array di valori
     * @param  string  $transform  Nome della trasformazione
     * @param  array<string, mixed>  $mappingRule  Regola di mapping completa (per contesto)
     * @return array<int, mixed>  Valori trasformati
     */
    private function applyTransform(array $values, string $transform, array $mappingRule): array
    {
        return match ($transform) {
            'uppercase' => array_map('strtoupper', $values),
            'lowercase' => array_map('strtolower', $values),
            'trim' => array_map('trim', $values),
            'date_parse' => array_map(fn ($v) => $this->transformDate($v), $values),
            'json_merge' => $this->transformJsonMerge($values, $mappingRule),
            default => $values,
        };
    }

    /**
     * Trasforma una data in formato ISO.
     *
     * @param  mixed  $value  Valore da trasformare
     * @return string|null  Data in formato ISO o null se non valida
     */
    private function transformDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // TODO: Implementare logica di parsing date ICCD
        // Per ora restituisce il valore originale
        return (string) $value;
    }

    /**
     * Trasforma valori multipli in un JSON merge.
     *
     * @param  array<int, mixed>  $values  Array di valori
     * @param  array<string, mixed>  $mappingRule  Regola di mapping
     * @return array<int, mixed>  Array con un solo elemento JSON
     */
    private function transformJsonMerge(array $values, array $mappingRule): array
    {
        // TODO: Implementare logica di merge JSON
        // Per ora restituisce il primo valore
        return !empty($values) ? [$values[0]] : [];
    }

    /**
     * Trasforma i campi MT/MIS in una struttura measurements completa.
     *
     * Legge tutti i sotto-campi MT/MIS/* (MISU, MISL, MISA, MISP) e li unifica
     * in una struttura JSON strutturata per records.ext_json.measurements.
     *
     * Struttura finale:
     * {
     *   "measurements": {
     *     "unit": "cm",
     *     "length": 80,
     *     "height": 120,
     *     "depth": null,
     *     "source": {
     *       "standard": "ICCD",
     *       "xpath": "MT/MIS"
     *     }
     *   }
     * }
     *
     * @param  array<string, array<int, mixed>>  $kvIndex  Indice completo KV (xpath => [values])
     * @param  array<string, mixed>  $mappingRule  Regola di mapping
     * @return array<int, mixed>  Array con un solo elemento JSON strutturato
     */
    private function transformMeasurementsMerge(array $kvIndex, array $mappingRule): array
    {
        $sourceField = $mappingRule['source_field'] ?? 'MT/MIS';
        
        // Legge i sotto-campi MT/MIS/*
        $unit = $this->getFirstValue($kvIndex, 'MT/MIS/MISU'); // Unità (cm, m, etc.)
        $length = $this->normalizeNumericValue($this->getFirstValue($kvIndex, 'MT/MIS/MISL')); // Lunghezza
        $height = $this->normalizeNumericValue($this->getFirstValue($kvIndex, 'MT/MIS/MISA')); // Altezza
        $depth = $this->normalizeNumericValue($this->getFirstValue($kvIndex, 'MT/MIS/MISP')); // Profondità (opzionale)

        // Se non ci sono dati, restituisce array vuoto
        if ($unit === null && $length === null && $height === null && $depth === null) {
            return [];
        }

        // Costruisce la struttura measurements
        $measurements = [
            'measurements' => [
                'unit' => $unit ?? null,
                'length' => $length,
                'height' => $height,
                'depth' => $depth,
                'source' => [
                    'standard' => 'ICCD',
                    'xpath' => $sourceField,
                ],
            ],
        ];

        return [$measurements];
    }

    /**
     * Ottiene il primo valore da un indice KV.
     *
     * @param  array<string, array<int, mixed>>  $kvIndex  Indice KV
     * @param  string  $xpath  XPath da cercare
     * @return mixed|null  Primo valore o null se non trovato
     */
    private function getFirstValue(array $kvIndex, string $xpath): mixed
    {
        $values = $kvIndex[$xpath] ?? [];
        return !empty($values) ? $values[0] : null;
    }

    /**
     * Normalizza un valore numerico da stringa a float/int.
     *
     * @param  mixed  $value  Valore da normalizzare
     * @return float|int|null  Valore normalizzato o null se non valido
     */
    private function normalizeNumericValue(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Se è già numerico, restituiscilo
        if (is_numeric($value)) {
            // Se è un intero, restituisci int, altrimenti float
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        // Prova a estrarre numeri dalla stringa (rimuove spazi, virgole, etc.)
        $cleaned = preg_replace('/[^\d.,-]/', '', (string) $value);
        $cleaned = str_replace(',', '.', $cleaned); // Sostituisce virgola con punto

        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }

        $numeric = (float) $cleaned;
        
        // Se il valore normalizzato è 0 ma l'originale non era "0", potrebbe essere un errore
        // Ma per ora accettiamo anche 0 come valore valido
        return $numeric;
    }

    /**
     * Ottiene la configurazione globale.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Ottiene il valore di una configurazione specifica.
     *
     * @param  string  $key  Chiave di configurazione (supporta dot notation)
     * @param  mixed  $default  Valore di default
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Parse YAML content.
     * Usa Symfony YAML se disponibile, altrimenti fallback a JSON.
     *
     * @param  string  $content  Contenuto YAML
     * @return array<string, mixed>  Array parsato
     *
     * @throws \RuntimeException Se il parsing fallisce
     */
    private function parseYaml(string $content): array
    {
        // Prova a usare Symfony YAML se disponibile
        if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::parse($content);
        }

        // Fallback: suggerisce l'installazione di symfony/yaml
        Log::warning('MappingResolver: symfony/yaml not installed, YAML parsing requires symfony/yaml package');
        throw new \RuntimeException(
            'YAML parsing requires symfony/yaml package. '.
            'Install it with: composer require symfony/yaml '.
            'Or use JSON format for mapping files.'
        );
    }
}
