<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calcola il titolo italiano (testo da record_kv) per la chiave API title_it in record_json.
 * Logica per card_type come da specifica Master Data / API cardData.
 */
final class CardTitleItResolver
{
    /**
     * @param  string  $idScheda  UUID record (records.id)
     * @param  string|null  $selCardType  card_type normalizzato o null
     */
    public function getCardTitleIt(string $idScheda, ?string $selCardType): string
    {
        $type = $selCardType === null ? '' : strtoupper(trim($selCardType));

        $valueTitleIt = match ($type) {
            'OA', 'D' => $this->titleOa($idScheda),
            'S', 'MI' => $this->titleS($idScheda),
            'F' => $this->titleF($idScheda),
            'MIDF' => $this->titleMidf($idScheda),
            'MINV' => $this->titleMinv($idScheda),
            'JSON' => $this->titleJsonOrMi($idScheda),
            'SBN' => $this->titleSbn($idScheda),
            'INTERVISTA' => $this->titleIntervista($idScheda),
            default => '',
        };

        if ( $type == 'MIDF') return $valueTitleIt;
        
        return $this->sanitizeValueTitleIt($valueTitleIt);
    }

    /**
     * Consente solo a–w, A–W, virgola e punto; ogni altro carattere (per codepoint UTF-8) diventa spazio.
     * Infine trim sulla stringa risultante.
     */
    private function sanitizeValueTitleIt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        //$value = str_replace('|', ',', $value);
        //$value = str_replace('/', ',', $value);

        // filtra caratteri (aggiunto il punto)
        $value = preg_replace("/[^\p{Latin},.\-\/'’|]/u", ' ', $value);

        // Rimuove i caratteri di punteggiatura all'inizio della stringa
        $value = preg_replace('/^[\s\.,;:]+/u', '', $value);

        // pulizia spazi multipli
        $value = preg_replace('/\s+/', ' ', $value);

        /*
        $A = 'titolo attribuito';
        $B = 'titolo proprio';
        $C = 'titoli attribuiti';
        $D = 'titoli proprii';

        $value = str_replace($A, '', $value);
        $value = str_replace($B, '', $value);
        $value = str_replace($C, '', $value);
        $value = str_replace($D, '', $value);
        */

        return trim($value);
    }

    /** Lettere ASCII a–w e A–W, più ',' e '.'. */
    /* Funzione non usata */
    /*
    private function isAllowedTitleItCharacter(string $ch): bool
    {
        if ($ch === ',' || $ch === '-' || $ch === '/' || $ch === '(' || $ch === ')' ) {
            return true;
        }

        return preg_match('/\p{L}/u', $ch) === 1;
    }
    */

    private function titleOa(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.key, b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key IN (?, ?, ?) AND a.id = ?
             ORDER BY b.id ASC',
            ['OG/SGT/SGTT', 'OG/SGT/SGTI', 'OG/OGT/OGTD', $idScheda]
        );

        return $this->firstValidByKeysInPriorityOrder($rows, [
            'OG/SGT/SGTT',
            'OG/SGT/SGTI',
            'OG/OGT/OGTD',
        ]);
    }

    private function titleS(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.key, b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key IN (?, ?, ?, ?) AND a.id = ?
             ORDER BY b.id ASC',
            ['OG/SGT/SGTP', 'OG/SGT/SGTT', 'OG/SGT/SGTI', 'OG/OGT/OGTD', $idScheda]
        );

        return $this->firstValidByKeysInPriorityOrder($rows, [
            'OG/SGT/SGTP',
            'OG/SGT/SGTT',
            'OG/SGT/SGTI',
            'OG/OGT/OGTD',
        ]);
    }

    private function titleF(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.key, b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key IN (?, ?, ?, ?) AND a.id = ?
             ORDER BY b.id ASC',
            ['SG/SGL/SGLT', 'SG/SGL/SGLA', 'SG/SGT/SGTI', 'OG/OGT/OGTD', $idScheda]
        );

        return $this->firstValidByKeysInPriorityOrder($rows, [
            'SG/SGL/SGLT',
            'SG/SGL/SGLA',
            'SG/SGT/SGTI',
            'OG/OGT/OGTD',
        ]);
    }

    private function titleMidf(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.field_name as key, b.text_value as value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.i18n_texts b ON b.entity_id = a.id
             WHERE b.lang = ? and b.field_name IN (?, ?, ?) AND a.id = ? 
             ORDER BY b.id ASC',
            ['en','OG/OGN', 'OG/OGD', 'DA/SGI', $idScheda]

        );

        return $this->firstValidByKeysInPriorityOrder($rows, [
            'OG/OGN',
            'OG/OGD',
            'DA/SGI',
        ]);
    }

    private function titleMinv(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.field_name as key, b.text_value as value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.i18n_texts b ON b.entity_id = a.id
             WHERE b.lang = ? and b.field_name IN (?, ?, ?) AND a.id = ? 
             ORDER BY b.id ASC',
            ['en','OG/OGN', 'OG/OGD', 'DA/SGI', $idScheda]

        );

        return $this->firstValidByKeysInPriorityOrder($rows, [
            'OG/OGN',
            'OG/OGD',
            'DA/SGI',
        ]);
    }    

    private function titleJsonOrMi(string $idScheda): string
    {
        $row = DB::selectOne(
            'SELECT b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key = ? AND a.id = ?
             ORDER BY b.id ASC
             LIMIT 1',
            ['title', $idScheda]
        );

        return $this->valueOrEmpty($row?->value_text);
    }

    private function titleSbn(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.key, b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key IN (?, ?) AND a.id = ?
             ORDER BY b.id ASC',
            ['245a', '245c', $idScheda]
        );

        $firstByKey = [];
        foreach ($rows as $row) {
            $k = (string) ($row->key ?? '');
            if ($k === '' || array_key_exists($k, $firstByKey)) {
                continue;
            }
            $firstByKey[$k] = $row->value_text;
        }

        $a = $this->valueOrEmpty($firstByKey['245a'] ?? null);
        $b = $this->valueOrEmpty($firstByKey['245c'] ?? null);

        if ($a !== '' && $b !== '') {
            return $a.' '.$b;
        }
        if ($a !== '') {
            return $a;
        }
        if ($b !== '') {
            return $b;
        }

        return '';
    }

    private function titleIntervista(string $idScheda): string
    {
        $rows = DB::select(
            'SELECT b.key, b.value_text FROM iartnet_master.records a
             INNER JOIN iartnet_master.record_kv b ON b.record_id = a.id
             WHERE b.key IN (?, ?) AND a.id = ?
             ORDER BY b.id ASC',
            ['intervistatore', 'intervistato', $idScheda]
        );

        $firstByKey = [];
        foreach ($rows as $row) {
            $k = (string) ($row->key ?? '');
            if ($k === '' || array_key_exists($k, $firstByKey)) {
                continue;
            }
            $firstByKey[$k] = $row->value_text;
        }

        $a = $this->valueOrEmpty($firstByKey['intervistatore'] ?? null);
        $b = $this->valueOrEmpty($firstByKey['intervistato'] ?? null);

        if ($a !== '' && $b !== '') {
            return $a.' and '.$b;
        }
        if ($a !== '') {
            return $a;
        }
        if ($b !== '') {
            return $b;
        }

        return '';        
    }

    /**
     * Per ogni key in $keyPriority (ordine = priorità), usa il primo value_text valido;
     * tra righe con la stessa key vale la prima per ORDER BY id della query (prima occorrenza in $rows).
     *
     * @param  list<object>  $rows  righe con ->key, ->value_text
     * @param  list<string>  $keyPriority  chiavi in ordine di preferenza (es. primaria, secondaria, …)
     */
    private function firstValidByKeysInPriorityOrder(array $rows, array $keyPriority): string
    {
        /*
        $rowsForLog = array_map(static function (object $row): array {
            return [
                'key' => $row->key ?? null,
                'value_text' => $row->value_text ?? null,
            ];
        }, $rows);

        Log::debug('CardTitleItResolver::firstValidByKeysInPriorityOrder', [
            'rows' => $rowsForLog,
            'keyPriority' => $keyPriority,
        ]);
        */

        $firstByKey = [];
        foreach ($rows as $row) {
            $k = (string) ($row->key ?? '');
            if ($k === '' || array_key_exists($k, $firstByKey)) {
                continue;
            }
            $firstByKey[$k] = $row->value_text;
        }

        foreach ($keyPriority as $key) {
            $candidate = $this->valueOrEmpty($firstByKey[$key] ?? null);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /** Valido se NOT NULL e diverso da stringa vuota (allineato a value_text <> ''). */
    private function valueOrEmpty(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $value;
    }
}
