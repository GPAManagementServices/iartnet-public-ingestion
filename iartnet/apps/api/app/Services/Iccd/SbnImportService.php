<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use App\Data\Iccd\ImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SbnImportService
{
    public function __construct(
        private readonly Marc21Parser $parser,
        private readonly MirrorRecordPersistenceService $persistence,
    ) {
    }

    /**
     * Run SBN import process.
     *
     * @param  ImportRun  $importRun  Import run configuration
     * @return array{imported: int, skipped: int, errors: int, details: array}
     *
     * @throws RuntimeException If import fails
     */
    public function runImport(ImportRun $importRun): array
    {
        Log::info("Starting SBN import", [
            'run_id' => $importRun->runId,
            'target_schema' => $importRun->targetSchema,
        ]);

        // Verify target schema exists and is accessible
        $this->verifyTargetSchema($importRun->targetSchema);

        // Record import run start in database
        $importRunId = $this->recordImportRunStart($importRun);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        Log::info("SBN import: Starting file search", [
            'extraction_path' => $importRun->extractionPath,
            'path_exists' => is_dir($importRun->extractionPath),
        ]);

        try {
            // Parse and import XML files
            // Search in root directory and recursively in subdirectories (same as ICCD)
            $xmlFiles = [];
            
            // Search in root directory
            $rootPattern = $importRun->extractionPath.'/*.xml';
            Log::debug("SBN import: Searching root pattern", ['pattern' => $rootPattern]);
            $rootFiles = glob($rootPattern);
            if ($rootFiles !== false) {
                $xmlFiles = array_merge($xmlFiles, $rootFiles);
                Log::debug("SBN import: Root files found", ['count' => count($rootFiles)]);
            } else {
                Log::warning("SBN import: glob() returned false for root pattern", ['pattern' => $rootPattern]);
            }
            
            // Search recursively in subdirectories
            $recursivePattern = $importRun->extractionPath.'/**/*.xml';
            Log::debug("SBN import: Searching recursive pattern", ['pattern' => $recursivePattern]);
            $recursiveFiles = glob($recursivePattern);
            if ($recursiveFiles !== false) {
                $xmlFiles = array_merge($xmlFiles, $recursiveFiles);
                Log::debug("SBN import: Recursive files found", ['count' => count($recursiveFiles)]);
            } else {
                Log::warning("SBN import: glob() returned false for recursive pattern", ['pattern' => $recursivePattern]);
            }
            
            // Remove duplicates
            $xmlFiles = array_unique($xmlFiles);

            Log::info("SBN import: XML files found", [
                'run_id' => $importRun->runId,
                'xml_files_count' => count($xmlFiles),
                'extraction_path' => $importRun->extractionPath,
                'xml_files' => array_map('basename', $xmlFiles),
            ]);

            if (empty($xmlFiles)) {
                Log::warning("SBN import: No XML files found in extraction path", [
                    'extraction_path' => $importRun->extractionPath,
                    'path_exists' => is_dir($importRun->extractionPath),
                    'path_readable' => is_readable($importRun->extractionPath),
                ]);
            }

            foreach ($xmlFiles as $xmlFile) {
                try {
                    Log::info("SBN import: Parsing XML file", [
                        'file' => basename($xmlFile),
                        'full_path' => $xmlFile,
                        'file_exists' => file_exists($xmlFile),
                        'file_readable' => is_readable($xmlFile),
                    ]);

                    if (!file_exists($xmlFile)) {
                        Log::error("SBN import: XML file does not exist", ['file' => $xmlFile]);
                        $errors++;
                        continue;
                    }

                    if (!is_readable($xmlFile)) {
                        Log::error("SBN import: XML file is not readable", ['file' => $xmlFile]);
                        $errors++;
                        continue;
                    }

                    $records = $this->parser->parseMarc21File($xmlFile);

                    Log::info("SBN import: Parser returned", [
                        'file' => basename($xmlFile),
                        'records_count' => count($records),
                    ]);

                    if (empty($records)) {
                        Log::warning("SBN XML file contains no records", [
                            'file' => basename($xmlFile),
                            'full_path' => $xmlFile,
                        ]);
                        continue;
                    }

                    Log::info("SBN import: Processing file", [
                        'file' => basename($xmlFile),
                        'records_count' => count($records),
                    ]);

                    foreach ($records as $recordIndex => $record) {
                        try {
                            Log::debug("SBN import: Processing record", [
                                'record_index' => $recordIndex,
                                'record_id' => $record['record_id'] ?? 'NOT_SET',
                                'has_fields' => isset($record['fields']),
                                'fields_count' => count($record['fields'] ?? []),
                            ]);

                            if (empty($record['record_id'])) {
                                Log::warning("SBN import: Record skipped - missing record_id", [
                                    'file' => basename($xmlFile),
                                    'record_index' => $recordIndex,
                                ]);
                                $skipped++;
                                continue;
                            }

                            Log::info("SBN import: Importing record", [
                                'record_id' => $record['record_id'],
                                'fields_count' => count($record['fields'] ?? []),
                            ]);

                            $outcome = DB::transaction(function () use ($importRun, $importRunId, $record, $recordIndex): array {
                                $result = $this->importRecord(
                                    $importRun->targetSchema,
                                    $importRunId,
                                    $record,
                                    $recordIndex
                                );
                                $this->importKeyValuePairs($importRun->targetSchema, $result['record_id'], $record['fields']);

                                return $result;
                            });

                            Log::info("SBN import: Record imported successfully", [
                                'record_id' => $outcome['record_id'],
                                'file' => basename($xmlFile),
                                'was_update' => $outcome['was_update'],
                            ]);

                            if ($outcome['was_update']) {
                                $updated++;
                            }
                            $imported++;
                        } catch (\Exception $e) {
                            $errors++;
                            Log::error("SBN import: Failed to import record", [
                                'file' => basename($xmlFile),
                                'record_index' => $recordIndex,
                                'record_id' => $record['record_id'] ?? 'unknown',
                                'error' => $e->getMessage(),
                                'error_code' => $e->getCode(),
                                'error_file' => $e->getFile(),
                                'error_line' => $e->getLine(),
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("Failed to import SBN XML file", [
                        'file' => $xmlFile,
                        'error' => $e->getMessage(),
                        'error_code' => $e->getCode(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            $errors++;
            Log::error("SBN import error", [
                'run_id' => $importRun->runId,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->recordImportRunError($importRun->targetSchema, $importRunId, $e->getMessage());

            // Non rilanciare l'eccezione per permettere il completamento del processo
            // throw $e;
        }

        // Record import run completion
        $this->recordImportRunCompletion($importRun->targetSchema, $importRunId, $imported, $updated, $skipped, $errors);

        Log::info("SBN import completed", [
            'run_id' => $importRun->runId,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /**
     * Verify target schema exists and is accessible.
     */
    private function verifyTargetSchema(string $schemaName): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $schemaName)) {
            throw new RuntimeException("Invalid schema name: {$schemaName}");
        }

        $exists = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
            [$schemaName]
        );

        if (! ($exists->exists ?? false)) {
            throw new RuntimeException("Target schema does not exist: {$schemaName}");
        }

        $tableExists = DB::selectOne(
            "SELECT EXISTS(
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = ? AND table_name = 'import_run'
            ) as exists",
            [$schemaName]
        );

        if (! ($tableExists->exists ?? false)) {
            throw new RuntimeException("Table import_run does not exist in schema: {$schemaName}");
        }
    }

    /**
     * Record import run start in database.
     */
    private function recordImportRunStart(ImportRun $importRun): string
    {
        $importRunId = $importRun->runId;
        $sourceZip = basename($importRun->extractionPath).'.zip';

        $informa = [
            'run_id' => $importRun->runId,
            'status' => 'running',
            'format' => 'SBN',
            'total_files' => $importRun->totalFiles,
            'xml_files' => $importRun->xmlFiles,
            'media_files' => $importRun->mediaFiles,
            'started_at' => now()->toIso8601String(),
        ];

        DB::statement("
            INSERT INTO \"{$importRun->targetSchema}\".import_run 
            (import_run_id, created_at, source_zip, informa)
            VALUES (?, NOW(), ?, ?::jsonb)
        ", [
            $importRunId,
            $sourceZip,
            json_encode($informa),
        ]);

        return $importRunId;
    }

    /**
     * Record import run error.
     */
    private function recordImportRunError(string $schemaName, string $importRunId, string $errorMessage): void
    {
        DB::statement("
            UPDATE \"{$schemaName}\".import_run
            SET informa = COALESCE(informa, '{}'::jsonb) || ?::jsonb
            WHERE import_run_id = ?
        ", [
            json_encode([
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'error_message' => $errorMessage,
            ]),
            $importRunId,
        ]);
    }

    /**
     * Record import run completion.
     */
    private function recordImportRunCompletion(string $schemaName, string $importRunId, int $imported, int $updated, int $skipped, int $errors): void
    {
        DB::statement("
            UPDATE \"{$schemaName}\".import_run
            SET informa = COALESCE(informa, '{}'::jsonb) || ?::jsonb
            WHERE import_run_id = ?
        ", [
            json_encode([
                'status' => $errors > 0 ? 'failed' : 'success',
                'finished_at' => now()->toIso8601String(),
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ]),
            $importRunId,
        ]);
    }

    /**
     * Normalizza il valore del Tag 001 per l'uso come record_id nelle tabelle mirror.
     * 1) Elimina i primi 8 caratteri (es. IT\ICCU\).
     * 2) Elimina tutti i caratteri '\'.
     * Esempio: IT\ICCU\LO1\0745599 → LO10745599
     */
    private function normalizeSbnRecordId(string $rawFromTag001): string
    {
        $s = trim($rawFromTag001);
        if (strlen($s) >= 8) {
            $s = substr($s, 8);
        }
        return str_replace('\\', '', $s);
    }

    /**
     * Import record into record table.
     */
    private function importRecord(
        string $schemaName,
        string $importRunId,
        array $record,
        int $schedaIdx
    ): array {
        $recordId = $this->normalizeSbnRecordId($record['record_id'] ?? '');
        $title = trim($record['leader'] ?? '');

        if (empty($recordId)) {
            throw new RuntimeException("Cannot import record: record_id is empty");
        }

        $wasUpdate = $this->persistence->upsertRecord($schemaName, [
            'record_id' => $recordId,
            'import_run_id' => $importRunId,
            'scheda_idx' => $schedaIdx,
            'normativa_code' => 'MARC21',
            'normativa_version' => '0',
            'title' => $title,
            'valid_xsd' => true,
            'error_count' => 0,
        ]);

        Log::debug("SBN import: Record upserted into record table", [
            'record_id' => $recordId,
            'schema' => $schemaName,
            'import_run_id' => $importRunId,
            'scheda_idx' => $schedaIdx,
            'was_update' => $wasUpdate,
        ]);

        return [
            'record_id' => $recordId,
            'was_update' => $wasUpdate,
        ];
    }

    /**
     * Import key-value pairs into kv table.
     * If a field value is in subfield format ("code: value | code: value"), inserts one record per subfield
     * with xpath = TAG + CODE; otherwise one record with xpath = TAG.
     */
    private function importKeyValuePairs(string $schemaName, string $recordId, array $fields): void
    {
        $this->persistence->replaceKeyValuePairs($schemaName, $recordId);

        $xpathOccurrences = [];
        $insertedCount = 0;

        foreach ($fields as $field) {
            $tag = $field['tag'] ?? '';
            $value = $field['value'] ?? '';

            if (empty($tag)) {
                continue;
            }

            // Parse subfield format from parser: "code: value | code: value".
            // Split only on " | " when it starts a new subfield (alnum + ": "), so "|" inside a value (e.g. ISBD in 245$a) is preserved.
            $split = preg_split('/ \| (?=[a-z0-9]: )/', (string) $value);
            $subfieldParts = array_map('trim', is_array($split) ? $split : []);
            $subfieldRows = [];
            foreach ($subfieldParts as $part) {
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^([a-z0-9]):\s*(.*)$/s', $part, $m)) {
                    $subfieldRows[] = ['code' => $m[1], 'value' => $m[2]];
                }
            }

            if (! empty($subfieldRows)) {
                // One record per subfield: xpath = TAG + CODE
                foreach ($subfieldRows as $row) {
                    $xpath = $tag.$row['code'];
                    if (! isset($xpathOccurrences[$xpath])) {
                        $xpathOccurrences[$xpath] = 0;
                    } else {
                        $xpathOccurrences[$xpath]++;
                    }
                    try {
                        $this->persistence->insertKeyValuePair(
                            $schemaName,
                            $recordId,
                            $xpath,
                            $xpathOccurrences[$xpath],
                            $row['value']
                        );
                        $insertedCount++;
                    } catch (\Exception $e) {
                        Log::error("SBN import: Failed to insert KV pair", [
                            'record_id' => $recordId,
                            'xpath' => $xpath,
                            'value' => substr($row['value'], 0, 100),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } else {
                // No subfields (e.g. control field): one record, xpath = TAG
                if ((string) $value === '') {
                    continue;
                }
                $xpath = $tag;
                if (! isset($xpathOccurrences[$xpath])) {
                    $xpathOccurrences[$xpath] = 0;
                } else {
                    $xpathOccurrences[$xpath]++;
                }
                try {
                    $this->persistence->insertKeyValuePair(
                        $schemaName,
                        $recordId,
                        $xpath,
                        $xpathOccurrences[$xpath],
                        (string) $value
                    );
                    $insertedCount++;
                } catch (\Exception $e) {
                    Log::error("SBN import: Failed to insert KV pair", [
                        'record_id' => $recordId,
                        'xpath' => $xpath,
                        'value' => substr((string) $value, 0, 100),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::debug("SBN import: KV pairs inserted", [
            'record_id' => $recordId,
            'inserted_count' => $insertedCount,
            'total_fields' => count($fields),
        ]);
    }
}
