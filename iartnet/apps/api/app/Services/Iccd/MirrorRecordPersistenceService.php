<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use Illuminate\Support\Facades\DB;

/**
 * Persistenza condivisa per schede mirror: upsert su record, replace su kv/asset.
 * Al re-import imposta promoted = false per consentire la re-sincronizzazione verso Master.
 */
final class MirrorRecordPersistenceService
{
    /**
     * Inserisce o aggiorna un record mirror.
     *
     * @param  array{
     *     record_id: string,
     *     import_run_id: string,
     *     scheda_idx: int,
     *     normativa_code: string,
     *     normativa_version: string,
     *     title: ?string,
     *     valid_xsd: bool,
     *     error_count: int,
     *     nctr?: ?string,
     *     nctn?: ?string,
     * }  $data
     * @return bool  true se il record esisteva già (re-import), false se nuovo insert
     */
    public function upsertRecord(string $schemaName, array $data): bool
    {
        $recordId = $data['record_id'];

        $exists = DB::selectOne(
            "SELECT 1 AS one FROM \"{$schemaName}\".record WHERE record_id = ?",
            [$recordId]
        ) !== null;

        $hasIccdIdentifiers = array_key_exists('nctr', $data) || array_key_exists('nctn', $data);

        if ($hasIccdIdentifiers) {
            DB::statement("
                INSERT INTO \"{$schemaName}\".record
                (record_id, import_run_id, scheda_idx, normativa_code, normativa_version, nctr, nctn, title, valid_xsd, error_count, promoted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, false)
                ON CONFLICT (record_id) DO UPDATE SET
                    import_run_id = EXCLUDED.import_run_id,
                    scheda_idx = EXCLUDED.scheda_idx,
                    normativa_code = EXCLUDED.normativa_code,
                    normativa_version = EXCLUDED.normativa_version,
                    nctr = EXCLUDED.nctr,
                    nctn = EXCLUDED.nctn,
                    title = EXCLUDED.title,
                    valid_xsd = EXCLUDED.valid_xsd,
                    error_count = EXCLUDED.error_count,
                    promoted = false
            ", [
                $recordId,
                $data['import_run_id'],
                $data['scheda_idx'],
                $data['normativa_code'],
                $data['normativa_version'],
                $data['nctr'] ?? null,
                $data['nctn'] ?? null,
                $data['title'] ?? null,
                $data['valid_xsd'],
                $data['error_count'],
            ]);
        } else {
            DB::statement("
                INSERT INTO \"{$schemaName}\".record
                (record_id, import_run_id, scheda_idx, normativa_code, normativa_version, title, valid_xsd, error_count, promoted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, false)
                ON CONFLICT (record_id) DO UPDATE SET
                    import_run_id = EXCLUDED.import_run_id,
                    scheda_idx = EXCLUDED.scheda_idx,
                    normativa_code = EXCLUDED.normativa_code,
                    normativa_version = EXCLUDED.normativa_version,
                    title = EXCLUDED.title,
                    valid_xsd = EXCLUDED.valid_xsd,
                    error_count = EXCLUDED.error_count,
                    promoted = false
            ", [
                $recordId,
                $data['import_run_id'],
                $data['scheda_idx'],
                $data['normativa_code'],
                $data['normativa_version'],
                $data['title'] ?? null,
                $data['valid_xsd'],
                $data['error_count'],
            ]);
        }

        return $exists;
    }

    /**
     * Sostituisce tutti i KV di una scheda (delete + insert a cura del chiamante).
     */
    public function replaceKeyValuePairs(string $schemaName, string $recordId): void
    {
        DB::statement("DELETE FROM \"{$schemaName}\".kv WHERE record_id = ?", [$recordId]);
    }

    /**
     * Sostituisce gli asset di pacchetto di una scheda prima di un nuovo insert.
     */
    public function replacePackageAssets(string $schemaName, string $recordId): void
    {
        DB::statement("DELETE FROM \"{$schemaName}\".asset WHERE record_id = ?", [$recordId]);
    }

    /**
     * Inserisce una riga KV.
     */
    public function insertKeyValuePair(
        string $schemaName,
        string $recordId,
        string $xpath,
        int $occurrenceIdx,
        string $valueText
    ): void {
        DB::statement("
            INSERT INTO \"{$schemaName}\".kv
            (record_id, xpath, occurrence_idx, value_text)
            VALUES (?, ?, ?, ?)
        ", [
            $recordId,
            $xpath,
            $occurrenceIdx,
            $valueText,
        ]);
    }

    /**
     * Inserisce un asset di pacchetto con promoted = false.
     */
    public function insertPackageAsset(
        string $schemaName,
        string $recordId,
        string $filename,
        bool $existsFlag,
        ?int $sizeBytes
    ): void {
        DB::statement("
            INSERT INTO \"{$schemaName}\".asset
            (record_id, filename, exists_flag, size_bytes, promoted)
            VALUES (?, ?, ?, ?, false)
        ", [
            $recordId,
            $filename,
            $existsFlag,
            $sizeBytes,
        ]);
    }
}
