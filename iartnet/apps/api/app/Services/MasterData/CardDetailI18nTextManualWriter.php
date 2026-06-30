<?php

declare(strict_types=1);

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scrive il valore EN manuale su iartnet_master.i18n_texts per campi record (Card Details).
 */
final class CardDetailI18nTextManualWriter
{
    private const TABLE = 'iartnet_master.i18n_texts';

    /**
     * Da chiave flatten (es. record_fields.AU/AUTN) a field_name su i18n_texts (es. AU/AUTN).
     */
    public static function displayKeyToFieldName(string $displayKey): ?string
    {
        $displayKey = trim($displayKey);
        $prefix = 'record_fields.';
        if (! str_starts_with($displayKey, $prefix)) {
            return null;
        }
        $rest = substr($displayKey, strlen($prefix));

        return $rest !== '' ? $rest : null;
    }

    /**
     * Aggiorna o inserisce la riga (entity_type=record, lang=en, origin=manual dopo salvataggio).
     */
    public function upsertRecordEnglishManual(string $entityId, string $fieldName, string $textValue): void
    {
        $now = now();
        $updated = DB::table(self::TABLE)
            ->where('entity_type', 'record')
            ->where('entity_id', $entityId)
            ->where('field_name', $fieldName)
            ->where('lang', 'en')
            ->update([
                'text_value' => $textValue,
                'origin' => 'manual',
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            return;
        }

        DB::table(self::TABLE)->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'record',
            'entity_id' => $entityId,
            'field_name' => $fieldName,
            'lang' => 'en',
            'origin' => 'manual',
            'status' => 'draft',
            'text_value' => $textValue,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
