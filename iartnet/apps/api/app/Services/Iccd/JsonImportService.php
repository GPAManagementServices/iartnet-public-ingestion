<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use App\Data\Iccd\ImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JsonImportService
{
    public function __construct(
        private readonly JsonDublinCoreParser $parser,
        private readonly MirrorRecordPersistenceService $persistence,
    ) {
    }

    /**
     * Run JSON import process.
     *
     * @param  ImportRun  $importRun  Import run configuration
     * @return array{imported: int, skipped: int, errors: int, details: array}
     *
     * @throws RuntimeException If import fails
     */
    public function runImport(ImportRun $importRun): array
    {
        Log::info("Starting JSON import", [
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

        try {
            // Parse and import JSON files
            // Search in root directory and recursively in subdirectories (same as ICCD and SBN)
            $jsonFiles = [];
            
            // Search in root directory
            $rootFiles = glob($importRun->extractionPath.'/*.json');
            if ($rootFiles !== false) {
                $jsonFiles = array_merge($jsonFiles, $rootFiles);
            }
            
            // Search recursively in subdirectories
            $recursiveFiles = glob($importRun->extractionPath.'/**/*.json');
            if ($recursiveFiles !== false) {
                $jsonFiles = array_merge($jsonFiles, $recursiveFiles);
            }
            
            // Remove duplicates
            $jsonFiles = array_unique($jsonFiles);

            Log::info("JSON import: JSON files found", [
                'run_id' => $importRun->runId,
                'json_files_count' => count($jsonFiles),
                'extraction_path' => $importRun->extractionPath,
                'json_files' => array_map('basename', $jsonFiles),
            ]);

            if (empty($jsonFiles)) {
                Log::warning("JSON import: No JSON files found in extraction path", [
                    'extraction_path' => $importRun->extractionPath,
                ]);
            }

            foreach ($jsonFiles as $jsonFile) {
                try {
                    Log::info("JSON import: Parsing JSON file", [
                        'file' => basename($jsonFile),
                        'full_path' => $jsonFile,
                        'file_exists' => file_exists($jsonFile),
                        'file_readable' => is_readable($jsonFile),
                    ]);

                    if (!file_exists($jsonFile)) {
                        Log::error("JSON import: JSON file does not exist", ['file' => $jsonFile]);
                        $errors++;
                        continue;
                    }

                    if (!is_readable($jsonFile)) {
                        Log::error("JSON import: JSON file is not readable", ['file' => $jsonFile]);
                        $errors++;
                        continue;
                    }

                    $records = $this->parser->parseJsonFile($jsonFile);

                    Log::info("JSON import: Parser returned", [
                        'file' => basename($jsonFile),
                        'records_count' => count($records),
                    ]);

                    if (empty($records)) {
                        Log::warning("JSON file contains no records", [
                            'file' => basename($jsonFile),
                            'full_path' => $jsonFile,
                        ]);
                        continue;
                    }

                    foreach ($records as $recordIndex => $record) {
                        try {
                            if (empty($record['record_id'])) {
                                Log::warning("JSON import: Record skipped - missing record_id", [
                                    'file' => basename($jsonFile),
                                    'record_index' => $recordIndex,
                                ]);
                                $skipped++;
                                continue;
                            }

                            $outcome = DB::transaction(function () use ($importRun, $importRunId, $record, $recordIndex, $jsonFile): array {
                                $result = $this->importRecord(
                                    $importRun->targetSchema,
                                    $importRunId,
                                    $record,
                                    $recordIndex
                                );

                                $this->importKeyValuePairs($importRun->targetSchema, $result['record_id'], $record['fields']);

                                if (! empty($record['images'])) {
                                    $this->importImages($importRun, $importRunId, $result['record_id'], $record['images'], $jsonFile);
                                }

                                return $result;
                            });

                            Log::info("JSON import: Record imported successfully", [
                                'record_id' => $outcome['record_id'],
                                'file' => basename($jsonFile),
                                'was_update' => $outcome['was_update'],
                            ]);

                            if ($outcome['was_update']) {
                                $updated++;
                            }
                            $imported++;
                        } catch (\Exception $e) {
                            $errors++;
                            Log::error("JSON import: Failed to import record", [
                                'file' => basename($jsonFile),
                                'record_index' => $recordIndex,
                                'record_id' => $record['record_id'] ?? 'unknown',
                                'error' => $e->getMessage(),
                                'error_code' => $e->getCode(),
                                'error_file' => $e->getFile(),
                                'error_line' => $e->getLine(),
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("Failed to import JSON file", [
                        'file' => $jsonFile,
                        'error' => $e->getMessage(),
                        'error_code' => $e->getCode(),
                        'error_file' => $e->getFile(),
                        'error_line' => $e->getLine(),
                    ]);
                }
            }

        } catch (\Exception $e) {
            $errors++;
            Log::error("JSON import error", [
                'run_id' => $importRun->runId,
                'error' => $e->getMessage(),
            ]);

            $this->recordImportRunError($importRun->targetSchema, $importRunId, $e->getMessage());

            throw $e;
        }

        // Record import run completion
        $this->recordImportRunCompletion($importRun->targetSchema, $importRunId, $imported, $updated, $skipped, $errors);

        Log::info("JSON import completed", [
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
            'format' => 'JSON',
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
     * Import record into record table.
     */
    private function importRecord(
        string $schemaName,
        string $importRunId,
        array $record,
        int $schedaIdx
    ): array {
        $recordId = trim($record['record_id']);
        $title = trim($record['title'] ?? '');

        if (empty($recordId)) {
            throw new RuntimeException("Cannot import record: record_id is empty");
        }

        $wasUpdate = $this->persistence->upsertRecord($schemaName, [
            'record_id' => $recordId,
            'import_run_id' => $importRunId,
            'scheda_idx' => $schedaIdx,
            'normativa_code' => 'JSON',
            'normativa_version' => 'Scala',
            'title' => $title,
            'valid_xsd' => true,
            'error_count' => 0,
        ]);

        return [
            'record_id' => $recordId,
            'was_update' => $wasUpdate,
        ];
    }

    /**
     * Import key-value pairs into kv table.
     */
    private function importKeyValuePairs(string $schemaName, string $recordId, array $fields): void
    {
        $this->persistence->replaceKeyValuePairs($schemaName, $recordId);

        $xpathOccurrences = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? '';
            $value = $field['value'] ?? '';

            if (empty($key) || empty($value)) {
                continue;
            }

            // Track occurrence index
            if (! isset($xpathOccurrences[$key])) {
                $xpathOccurrences[$key] = 0;
            } else {
                $xpathOccurrences[$key]++;
            }

            $this->persistence->insertKeyValuePair(
                $schemaName,
                $recordId,
                $key,
                $xpathOccurrences[$key],
                $value
            );
        }
    }

    /**
     * Import images from JSON record.
     */
    private function importImages(
        ImportRun $importRun,
        string $importRunId,
        string $recordId,
        array $images,
        string $sourceJsonFile
    ): void {
        $this->persistence->replacePackageAssets($importRun->targetSchema, $recordId);

        // Create immagini folder in extraction path if it doesn't exist
        $immaginiPath = $importRun->extractionPath.'/immagini';
        if (! is_dir($immaginiPath)) {
            if (! mkdir($immaginiPath, 0755, true)) {
                Log::warning("Failed to create immagini directory", [
                    'path' => $immaginiPath,
                ]);
                return;
            }
        }

        foreach ($images as $idx => $image) {
            $field = $image['field'] ?? 'Anteprima';
            $data = $image['data'] ?? '';
            $format = $image['format'] ?? 'jpg';

            if (empty($data)) {
                continue;
            }

            // Generate filename: record_id_field_index.format
            $fileName = $recordId.'_'.$field.($idx > 0 ? '_'.$idx : '').'.'.$format;

            // Check if data is base64 encoded
            $imageData = $data;
            if (preg_match('/^data:image\/[^;]+;base64,/', $data)) {
                // Extract base64 data
                $imageData = base64_decode(preg_replace('/^data:image\/[^;]+;base64,/', '', $data));
            } elseif (str_starts_with($data, '/9j/') || str_starts_with($data, 'iVBOR')) {
                // Already base64 without data URI prefix
                $imageData = base64_decode($data);
            }

            if ($imageData === false) {
                // Try as file path
                $imagePath = $importRun->extractionPath.'/'.$data;
                if (file_exists($imagePath)) {
                    $targetPath = $immaginiPath.'/'.$fileName;
                    if (copy($imagePath, $targetPath)) {
                        $this->insertAsset($importRun->targetSchema, $recordId, $fileName, $immaginiPath);
                    }
                }
                continue;
            }

            // Save base64 decoded image
            $targetPath = $immaginiPath.'/'.$fileName;
            if (file_put_contents($targetPath, $imageData) !== false) {
                $this->insertAsset($importRun->targetSchema, $recordId, $fileName, $immaginiPath);
            } else {
                Log::warning("Failed to save image", [
                    'target_path' => $targetPath,
                    'record_id' => $recordId,
                ]);
            }
        }
    }

    /**
     * Insert asset record into asset table.
     */
    private function insertAsset(string $schemaName, string $recordId, string $fileName, string $immaginiPath): void
    {
        $existsFlag = true; // File was just created
        $sizeBytes = null;

        // Get file size if file exists
        $fullPath = $immaginiPath.'/'.$fileName;
        if (file_exists($fullPath)) {
            $sizeBytes = filesize($fullPath);
        }

        try {
            $this->persistence->insertPackageAsset(
                $schemaName,
                $recordId,
                $fileName,
                $existsFlag,
                $sizeBytes
            );
        } catch (\Exception $e) {
            Log::error("Failed to insert asset", [
                'record_id' => $recordId,
                'filename' => $fileName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
