<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IngestionPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MirrorDataService
{
    /**
     * Quote an identifier for PostgreSQL (uses double quotes).
     *
     * @param  string  $identifier  The identifier to quote
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Get all records from the mirror schema.
     *
     * @param  string  $schemaName  Schema name
     * @return array<int, object>
     *
     * @throws RuntimeException If query fails
     */
    public function getRecords(string $schemaName): array
    {
        $this->validateSchemaName($schemaName);

        try {
            $records = DB::select("
                SELECT 
                    record_id,
                    title,
                    nctr,
                    nctn,
                    normativa_code,
                    normativa_version,
                    valid_xsd,
                    error_count
                FROM {$this->quoteIdentifier($schemaName)}.record
                ORDER BY title ASC
            ");

            return $records;
        } catch (\Exception $e) {
            Log::error("Failed to get records from mirror schema", [
                'schema_name' => $schemaName,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to get records from mirror schema '{$schemaName}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get KV data for a specific record.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $recordId  Record ID
     * @return array<int, object>
     *
     * @throws RuntimeException If query fails
     */
    public function getKvData(string $schemaName, string $recordId): array
    {
        $this->validateSchemaName($schemaName);

        try {
            $kvData = DB::select("
                SELECT 
                    id,
                    xpath,
                    occurrence_idx,
                    value_text
                FROM {$this->quoteIdentifier($schemaName)}.kv
                WHERE record_id = ?
                ORDER BY xpath, occurrence_idx
            ", [$recordId]);

            return $kvData;
        } catch (\Exception $e) {
            Log::error("Failed to get KV data from mirror schema", [
                'schema_name' => $schemaName,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to get KV data from mirror schema '{$schemaName}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get assets for a specific record.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $recordId  Record ID
     * @return array<int, object>
     *
     * @throws RuntimeException If query fails
     */
    public function getAssets(string $schemaName, string $recordId): array
    {
        $this->validateSchemaName($schemaName);

        try {
            $assets = DB::select("
                SELECT 
                    id,
                    filename,
                    exists_flag,
                    size_bytes
                FROM {$this->quoteIdentifier($schemaName)}.asset
                WHERE record_id = ?
                ORDER BY filename
            ", [$recordId]);

            return $assets;
        } catch (\Exception $e) {
            Log::error("Failed to get assets from mirror schema", [
                'schema_name' => $schemaName,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to get assets from mirror schema '{$schemaName}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Find image file path for an asset.
     *
     * @param  string  $filename  File name
     * @return string|null  Full path to the file or null if not found
     */
    public function findImagePath(string $filename): ?string
    {
        // Search in ingestion root: extraction (runId dirs), tmp, runs
        $root = IngestionPaths::root();
        $searchPaths = [
            $root,
            $root.DIRECTORY_SEPARATOR.'tmp',
            $root.DIRECTORY_SEPARATOR.'runs',
        ];

        foreach ($searchPaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }

            // Search recursively
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === $filename) {
                    return $file->getRealPath();
                }
            }
        }

        return null;
    }

    /**
     * Validate schema name.
     *
     * @param  string  $schemaName  Schema name
     * @return void
     *
     * @throws RuntimeException If schema name is invalid
     */
    private function validateSchemaName(string $schemaName): void
    {
        if (empty($schemaName)) {
            throw new RuntimeException('Schema name cannot be empty');
        }

        // Basic validation - schema names should be lowercase alphanumeric with underscores
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $schemaName)) {
            throw new RuntimeException("Invalid schema name: {$schemaName}");
        }
    }
}
