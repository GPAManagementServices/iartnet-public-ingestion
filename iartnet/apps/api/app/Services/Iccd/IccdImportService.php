<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use App\Data\Iccd\ImportRun;
use App\Data\Iccd\ValidationIssue;
use App\Models\MirrorInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class IccdImportService
{
    /**
     * Cached data provider for the current import run.
     */
    private ?string $dataProvider = null;

    public function __construct(
        private readonly ZipPackageInspector $packageInspector,
        private readonly IccdXsd10ValidatorService $validator,
        private readonly XmlParser $xmlParser,
        private readonly MirrorRecordPersistenceService $persistence,
    ) {
    }

    /**
     * Run complete import process.
     *
     * @param  ImportRun  $importRun  Import run configuration
     * @param  array<string, array<ValidationIssue>>  $validationResults  Validation results by file
     * @param  bool  $proceedDespiteErrors  Whether to proceed even if validation has errors
     * @return array{imported: int, skipped: int, errors: int, details: array}
     *
     * @throws RuntimeException If import fails
     */
    public function runImport(ImportRun $importRun, array $validationResults, bool $proceedDespiteErrors = true): array
    {
        Log::info("Starting ICCD import", [
            'run_id' => $importRun->runId,
            'target_schema' => $importRun->targetSchema,
        ]);

        // Reset data provider cache for this import run
        $this->dataProvider = null;

        // Verify target schema exists and is accessible
        $this->verifyTargetSchema($importRun->targetSchema);

        // Check validation errors
        $hasErrors = $this->hasValidationErrors($validationResults);

        if ($hasErrors && ! $proceedDespiteErrors) {
            throw new RuntimeException('Import aborted due to validation errors. Set proceedDespiteErrors=true to continue.');
        }

        // Copy XML files to tmp directory
        $this->copyXmlFilesToTmp($importRun);

        // Record import run start in database
        $importRunId = $this->recordImportRunStart($importRun);
        // Use importRunId as batchId for other tables (record, kv, validation_issue, asset)
        $batchId = $importRunId;

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        // MIDF (SIGEC senza IMMFTAN): gli asset si ricavano da DO/DCM/DCMK in ogni scheda
        $dataProvider = $this->getDataProvider($importRun->targetSchema);
        $xmlFilesForImmftan = glob($importRun->tmpPath.'/*.xml');
        $hasImmftan = false;
        foreach ($xmlFilesForImmftan as $xf) {
            if (strtoupper(basename($xf)) === 'IMMFTAN.XML') {
                $hasImmftan = true;
                break;
            }
        }
        $isMidf = ($dataProvider === 'SIGEC' && ! $hasImmftan);

        try {
            // Import validation issues
            $this->importValidationIssues($importRun->targetSchema, $importRunId, $validationResults);

            // Parse and import XML files
            $xmlFiles = glob($importRun->tmpPath.'/*.xml');

            foreach ($xmlFiles as $xmlFile) {
                $fileName = basename($xmlFile);

                // Skip INFORMA and IMMFTAN (handled separately)
                if (in_array(strtoupper($fileName), ['INFORMA.XML', 'IMMFTAN.XML'], true)) {
                    continue;
                }

                try {
                    $parsed = $this->xmlParser->parseIccdFile($xmlFile);

                    // Get validation results for this file to determine valid_xsd and error_count
                    $fileValidationResults = $validationResults[$xmlFile] ?? [];
                    $hasValidationErrors = !empty(array_filter($fileValidationResults, fn($issue) =>
                        (is_array($issue) ? ($issue['severity'] ?? '') : $issue->severity) === 'error'
                    ));

                    $normativaVersion = '3.00'; // Default version, could be extracted from INFORMA if needed

                    foreach ($parsed['schede'] as $schedaIdx => $scheda) {
                        // Determine normativa from XML field CD/TSK
                        $normativaCode = $this->extractNormativaCode($scheda);

                        $importOutcome = DB::transaction(function () use (
                            $importRun,
                            $importRunId,
                            $scheda,
                            $schedaIdx,
                            $normativaCode,
                            $normativaVersion,
                            $hasValidationErrors,
                            $fileValidationResults,
                            $isMidf
                        ): array {
                            $outcome = $this->importRecord(
                                $importRun->targetSchema,
                                $importRunId,
                                $scheda,
                                $schedaIdx,
                                $normativaCode,
                                $normativaVersion,
                                ! $hasValidationErrors,
                                count(array_filter($fileValidationResults, fn ($issue) => (is_array($issue) ? ($issue['severity'] ?? '') : $issue->severity) === 'error'))
                            );
                            $this->importKeyValuePairs($importRun->targetSchema, $outcome['record_id'], $scheda['kv_pairs']);

                            // MIDF: asset da DO/DCM/DCMK nella scheda (più nodi DCMK per scheda)
                            if ($isMidf) {
                                $this->importAssetsFromDcmDcmkForScheda($importRun, $importRunId, $outcome['record_id'], $scheda);
                            }

                            return $outcome;
                        });

                        if ($importOutcome['was_update']) {
                            $updated++;
                        }
                        $imported++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("Failed to import XML file", [
                        'file' => $xmlFile,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Import assets from IMMFTAN and media files (salto se MIDF: già gestito per scheda)
            $this->importAssets($importRun, $importRunId, $isMidf);

        } catch (\Exception $e) {
            $errors++;
            Log::error("Import error", [
                'run_id' => $importRun->runId,
                'error' => $e->getMessage(),
            ]);

            $this->recordImportRunError($importRun->targetSchema, $importRunId, $e->getMessage());

            throw $e;
        }

        // Record import run completion
        $this->recordImportRunCompletion($importRun->targetSchema, $importRunId, $imported, $updated, $skipped, $errors);

        // Save import results to JSON
        $this->saveImportResults($importRun, [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ]);

        Log::info("ICCD import completed", [
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
     *
     * @param  string  $schemaName  Schema name
     * @return void
     *
     * @throws RuntimeException If schema doesn't exist or is not accessible
     */
    private function verifyTargetSchema(string $schemaName): void
    {
        // Validate schema name format
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $schemaName)) {
            throw new RuntimeException("Invalid schema name: {$schemaName}");
        }

        // Check if schema exists
        $exists = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
            [$schemaName]
        );

        if (! ($exists->exists ?? false)) {
            throw new RuntimeException("Target schema does not exist: {$schemaName}");
        }

        // Verify import_run table exists in schema
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
     * Check if validation results contain errors.
     *
     * @param  array<string, array<ValidationIssue|array>>  $validationResults  Validation results (can be objects or arrays)
     * @return bool
     */
    private function hasValidationErrors(array $validationResults): bool
    {
        foreach ($validationResults as $issues) {
            foreach ($issues as $issue) {
                // Handle both ValidationIssue objects and arrays (from Livewire serialization)
                $severity = is_array($issue) 
                    ? ($issue['severity'] ?? '')
                    : $issue->severity;
                
                if ($severity === 'error') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Copy XML files to temporary storage.
     *
     * @param  ImportRun  $importRun  Import run
     * @return void
     */
    private function copyXmlFilesToTmp(ImportRun $importRun): void
    {
        $tmpDir = $importRun->tmpPath;

        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        // Copy XML files from extraction path to tmp
        // Search in root directory and recursively in subdirectories
        $xmlFiles = [];
        
        // Search in root directory
        $rootFiles = glob($importRun->extractionPath.'/*.xml');
        if ($rootFiles !== false) {
            $xmlFiles = array_merge($xmlFiles, $rootFiles);
        }
        
        // Search recursively in subdirectories
        $recursiveFiles = glob($importRun->extractionPath.'/**/*.xml');
        if ($recursiveFiles !== false) {
            $xmlFiles = array_merge($xmlFiles, $recursiveFiles);
        }
        
        // Remove duplicates
        $xmlFiles = array_unique($xmlFiles);

        foreach ($xmlFiles as $xmlFile) {
            $targetFile = $tmpDir.'/'.basename($xmlFile);

            if (! copy($xmlFile, $targetFile)) {
                Log::warning("Failed to copy XML file to tmp", [
                    'source' => $xmlFile,
                    'target' => $targetFile,
                ]);
            }
        }

        Log::info("XML files copied to tmp", [
            'tmp_path' => $tmpDir,
            'files_count' => count($xmlFiles),
        ]);
    }

    /**
     * Record import run start in database.
     *
     * @param  ImportRun  $importRun  Import run
     * @return string  Import run ID (UUID)
     */
    private function recordImportRunStart(ImportRun $importRun): string
    {
        $importRunId = $importRun->runId;

        // Extract source ZIP name from extraction path
        // extractionPath from IngestionPaths::extractionPath($runId) (configurable via INGEST_FS_ROOT)
        // We need the original ZIP filename - try to get it from package info or use runId
        $sourceZip = basename($importRun->extractionPath).'.zip';

        // Store metadata in informa jsonb field
        $informa = [
            'run_id' => $importRun->runId,
            'status' => 'running',
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
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $importRunId  Import run ID
     * @param  string  $errorMessage  Error message
     * @return void
     */
    private function recordImportRunError(string $schemaName, string $importRunId, string $errorMessage): void
    {
        // Update informa jsonb field with error information
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
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $importRunId  Import run ID
     * @param  int  $imported  Number of imported items
     * @param  int  $skipped  Number of skipped items
     * @param  int  $errors  Number of errors
     * @return void
     */
    private function recordImportRunCompletion(string $schemaName, string $importRunId, int $imported, int $updated, int $skipped, int $errors): void
    {
        // Update informa jsonb field with completion information
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
     * Save import results to JSON file.
     *
     * @param  ImportRun  $importRun  Import run
     * @param  array  $results  Import results
     * @return void
     */
    private function saveImportResults(ImportRun $importRun, array $results): void
    {
        $jsonPath = $importRun->getImportJsonPath();

        if (! is_dir(dirname($jsonPath))) {
            mkdir(dirname($jsonPath), 0755, true);
        }

        file_put_contents($jsonPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Import record (scheda) into record table.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $importRunId  Import run ID
     * @param  array  $scheda  Scheda data
     * @param  int  $schedaIdx  Index of scheda in file (0-based)
     * @param  string  $normativaCode  Normativa code (OA, S, etc.)
     * @param  string  $normativaVersion  Normativa version
     * @param  bool  $validXsd  Whether XSD validation passed
     * @param  int  $errorCount  Number of validation errors
     * @return array{record_id: string, was_update: bool}  Record ID (scheda_id) e flag re-import
     *
     * @note Table structure: record_id (TEXT, PK), import_run_id (TEXT), scheda_idx (INT), normativa_code (TEXT), normativa_version (TEXT), nctr (TEXT), nctn (TEXT), title (TEXT), valid_xsd (BOOLEAN), error_count (INT)
     */
    private function importRecord(
        string $schemaName,
        string $importRunId,
        array $scheda,
        int $schedaIdx,
        string $normativaCode,
        string $normativaVersion,
        bool $validXsd,
        int $errorCount
    ): array {
        // Get data provider for this schema (cached)
        $dataProvider = $this->getDataProvider($schemaName);

        // Build record_id based on data_provider
        if ($dataProvider === 'SIRBEC') {
            // Per SIRBEC il codice è: valore in TSK + "_" + valore in ACSC (es. MI_4t010-00010)
            $acsc = $this->extractAcsc($scheda);
            if (empty($acsc)) {
                throw new RuntimeException(
                    "SIRBEC import: Campo 'Codice SIRBeC IDK' (AC/ACS/ACSC) non trovato nella scheda. ".
                    "Scheda ID: ".($scheda['id'] ?? 'unknown')
                );
            }
            $tsk = $this->extractTsk($scheda);
            $recordId = ($tsk !== null && trim($tsk) !== '')
                ? trim($tsk) . '_' . preg_replace('/\s+/', ' ', trim($acsc))
                : preg_replace('/\s+/', ' ', trim($acsc));
        } else {
            // For SIGEC and others, use scheda_id (from scheda['id'])
            // Normalize whitespace to ensure consistency
            $recordId = preg_replace('/\s+/', ' ', trim($scheda['id']));
        }

        // Extract nctr and nctn from scheda XML
        $nctr = $this->extractNctr($scheda);
        $nctn = $this->extractNctn($scheda);

        // Extract title from scheda XML
        $title = $this->extractTitle($scheda);

        $wasUpdate = $this->persistence->upsertRecord($schemaName, [
            'record_id' => $recordId,
            'import_run_id' => $importRunId,
            'scheda_idx' => $schedaIdx,
            'normativa_code' => $normativaCode,
            'normativa_version' => $normativaVersion,
            'nctr' => $nctr,
            'nctn' => $nctn,
            'title' => $title,
            'valid_xsd' => $validXsd,
            'error_count' => $errorCount,
        ]);

        return [
            'record_id' => $recordId,
            'was_update' => $wasUpdate,
        ];
    }

    /**
     * Import key-value pairs into kv table.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $recordId  Record ID (scheda_id)
     * @param  array<array{key: string, value: string}>  $kvPairs  Key-value pairs
     * @return void
     *
     * @note Table structure: id (BIGINT, auto), record_id (TEXT), xpath (TEXT), occurrence_idx (INT), value_text (TEXT)
     */
    private function importKeyValuePairs(string $schemaName, string $recordId, array $kvPairs): void
    {
        $this->persistence->replaceKeyValuePairs($schemaName, $recordId);

        // Track occurrence indices for each xpath
        $xpathOccurrences = [];

        foreach ($kvPairs as $pair) {
            $xpath = $pair['key']; // The key from parser is already the xpath
            $valueText = $pair['value'];

            // Track occurrence index for this xpath
            if (! isset($xpathOccurrences[$xpath])) {
                $xpathOccurrences[$xpath] = 0;
            } else {
                $xpathOccurrences[$xpath]++;
            }

            $this->persistence->insertKeyValuePair(
                $schemaName,
                $recordId,
                $xpath,
                $xpathOccurrences[$xpath],
                $valueText
            );
        }
    }

    /**
     * Import validation issues into validation_issue table.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $importRunId  Import run ID
     * @param  array<string, array<ValidationIssue|array>>  $validationResults  Validation results (can be objects or arrays)
     * @return void
     *
     * @note Table structure: id (BIGINT, auto), import_run_id (TEXT), record_id (TEXT), cycle (INT), severity (TEXT), source (TEXT), message (TEXT), path (TEXT), line (INT), col (INT), reason (TEXT)
     */
    private function importValidationIssues(string $schemaName, string $importRunId, array $validationResults): void
    {
        // Insert into validation_issue table
        // Structure: id (auto), import_run_id, record_id, cycle, severity, source, message, path, line, col, reason
        $cycle = 1; // Default cycle number (can be adjusted if needed)

        foreach ($validationResults as $filePath => $issues) {
            foreach ($issues as $issue) {
                // Handle both ValidationIssue objects and arrays (from Livewire serialization)
                if (is_array($issue)) {
                    // Extract from array (Livewire format)
                    $file = $issue['file'] ?? basename($filePath);
                    $recordId = $issue['scheda_id'] ?? null; // scheda_id maps to record_id
                    $severity = $issue['severity'] ?? 'error';
                    $message = $issue['message'] ?? '';
                    $line = $issue['line'] ?? null;
                    $column = $issue['column'] ?? null;
                } else {
                    // Extract from ValidationIssue object
                    $file = $issue->file;
                    $recordId = $issue->schedaId; // schedaId maps to record_id
                    $severity = $issue->severity;
                    $message = $issue->message;
                    $line = $issue->line;
                    $column = $issue->column;
                }

                // Use file name as source, full path as path
                $source = $file;
                $path = $filePath;

                DB::statement("
                    INSERT INTO \"{$schemaName}\".validation_issue 
                    (import_run_id, record_id, cycle, severity, source, message, path, line, col)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $importRunId,
                    $recordId,
                    $cycle,
                    $severity,
                    $source,
                    $message,
                    $path,
                    $line,
                    $column,
                ]);
            }
        }
    }

    /**
     * Import assets from IMMFTAN and media files.
     * Se alreadyHandledMidf è true (SIGEC senza IMMFTAN), gli asset sono già stati importati da DO/DCM/DCMK; si salta.
     *
     * @param  ImportRun  $importRun  Import run
     * @param  string  $importRunId  Import run ID
     * @param  bool  $alreadyHandledMidf  True se MIDF: asset già importati da DCM/DCMK per scheda
     * @return void
     */
    private function importAssets(ImportRun $importRun, string $importRunId, bool $alreadyHandledMidf = false): void
    {
        if ($alreadyHandledMidf) {
            Log::info("MIDF: asset import già eseguito da DO/DCM/DCMK per scheda, skip IMMFTAN");
            return;
        }

        // Find IMMFTAN.xml in tmpPath (where XML files are copied)
        $immftanPath = null;
        $xmlFiles = glob($importRun->tmpPath.'/*.xml');

        foreach ($xmlFiles as $xmlFile) {
            if (strtoupper(basename($xmlFile)) === 'IMMFTAN.XML') {
                $immftanPath = $xmlFile;
                break;
            }
        }

        if ($immftanPath === null) {
            Log::warning("IMMFTAN.xml not found in tmpPath, skipping asset import", [
                'tmp_path' => $importRun->tmpPath,
            ]);
            return;
        }

        Log::info("Parsing IMMFTAN.xml for asset mappings", [
            'immftan_path' => $immftanPath,
        ]);

        // Parse IMMFTAN: file, nctr, nctn, opzionale rvel (disambigua NCTR+NCTN duplicati)
        $mappings = $this->xmlParser->parseImmftan($immftanPath);

        if (empty($mappings)) {
            Log::warning("No mappings found in IMMFTAN.xml", [
                'immftan_path' => $immftanPath,
            ]);
            return;
        }

        Log::info("Found mappings in IMMFTAN.xml", [
            'mappings_count' => count($mappings),
        ]);

        $importedCount = 0;
        $skippedCount = 0;
        $assetsReplacedForRecord = [];

        // Import assets: record_id da NCTR+NCTN; se IMMFTAN ha rvel valorizzato anche match su kv (NCT/RVEL o RV/RVE/RVEL)
        foreach ($mappings as $mapping) {
            $fileName = $mapping['file'] ?? null;
            $nctr = $mapping['nctr'] ?? null;
            $nctn = $mapping['nctn'] ?? null;
            $rvel = $mapping['rvel'] ?? null;

            if (empty($fileName)) {
                Log::warning("Mapping missing file name, skipping", [
                    'mapping' => $mapping,
                ]);
                $skippedCount++;
                continue;
            }

            // Find media file in extraction path (where images are extracted)
            $filePath = $this->findMediaFile($importRun->extractionPath, $fileName);

            if ($filePath === null) {
                Log::warning("Media file not found", [
                    'file' => $fileName,
                    'nctr' => $nctr,
                    'nctn' => $nctn,
                    'rvel' => $rvel,
                    'extraction_path' => $importRun->extractionPath,
                ]);
                $skippedCount++;
                continue;
            }

            $recordId = $this->findMirrorRecordIdForImmftan(
                $importRun->targetSchema,
                $importRunId,
                (string) ($nctr ?? ''),
                (string) ($nctn ?? ''),
                is_string($rvel) ? $rvel : null
            );

            if (empty($recordId)) {
                Log::warning("Record not found for asset mapping", [
                    'file' => $fileName,
                    'nctr' => trim((string) ($nctr ?? '')),
                    'nctn' => trim((string) ($nctn ?? '')),
                    'rvel' => $rvel,
                    'import_run_id' => $importRunId,
                ]);
                $skippedCount++;
                continue;
            }

            // Copy media file to tmpPath for persistence
            $tmpMediaPath = $this->copyMediaFileToTmp($importRun, $fileName, $filePath);
            
            // Use tmp path if copied, otherwise original path
            $finalFilePath = $tmpMediaPath ?? $filePath;
            
            // Check if file exists and get size
            $existsFlag = file_exists($finalFilePath);
            $sizeBytes = null;
            if ($existsFlag) {
                $sizeBytes = filesize($finalFilePath);
            }

            // Schema: id (BIGINT auto), record_id (TEXT), filename (TEXT), exists_flag (BOOLEAN), size_bytes (BIGINT)
            try {
                if (! isset($assetsReplacedForRecord[$recordId])) {
                    $this->persistence->replacePackageAssets($importRun->targetSchema, $recordId);
                    $assetsReplacedForRecord[$recordId] = true;
                }

                $this->persistence->insertPackageAsset(
                    $importRun->targetSchema,
                    $recordId,
                    $fileName,
                    $existsFlag,
                    $sizeBytes
                );

                $importedCount++;
                Log::debug("Asset imported successfully", [
                    'file' => $fileName,
                    'record_id' => $recordId,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to import asset", [
                    'file' => $fileName,
                    'record_id' => $recordId,
                    'error' => $e->getMessage(),
                ]);
                $skippedCount++;
            }
        }

        Log::info("Asset import completed", [
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'total_mappings' => count($mappings),
        ]);
    }

    /**
     * MIDF: estrae i nomi file immagine dalla sezione DO/DCM/DCMK della scheda (kv_pairs).
     * Possono esserci più nodi DCMK per scheda.
     *
     * @param  array  $scheda  Scheda con kv_pairs (key = path tipo DO/DCM/DCMK)
     * @return array<int, string>  Lista di filename (trimmed, non vuoti)
     */
    private function extractDcmkFilenamesFromScheda(array $scheda): array
    {
        $filenames = [];
        $isDcmk = static fn (string $k): bool => preg_match('#(^|/)DO/DCM/DCMK$#', $k) === 1;

        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            if (! $isDcmk($key)) {
                continue;
            }
            $value = trim((string) ($pair['value'] ?? ''));
            if ($value !== '') {
                $filenames[] = $value;
            }
        }

        return $filenames;
    }

    /**
     * MIDF: importa in asset i file indicati in DO/DCM/DCMK per una singola scheda.
     * Cerca il file in extractionPath, copia in tmp se necessario, inserisce riga in asset.
     *
     * @param  ImportRun  $importRun  Import run
     * @param  string  $importRunId  Import run ID (non usato per lookup record, solo contesto)
     * @param  string  $recordId  record_id della scheda
     * @param  array  $scheda  Scheda con kv_pairs
     * @return void
     */
    private function importAssetsFromDcmDcmkForScheda(ImportRun $importRun, string $importRunId, string $recordId, array $scheda): void
    {
        $filenames = $this->extractDcmkFilenamesFromScheda($scheda);
        if ($filenames === []) {
            return;
        }

        $schema = $importRun->targetSchema;
        $this->persistence->replacePackageAssets($schema, $recordId);

        foreach ($filenames as $fileName) {
            $filePath = $this->findMediaFile($importRun->extractionPath, $fileName);
            if ($filePath === null) {
                Log::debug("MIDF: media file not found for scheda", [
                    'record_id' => $recordId,
                    'file' => $fileName,
                    'extraction_path' => $importRun->extractionPath,
                ]);
                $existsFlag = false;
                $sizeBytes = null;
            }
            else {
                $tmpMediaPath = $this->copyMediaFileToTmp($importRun, $fileName, $filePath);
                $finalFilePath = $tmpMediaPath ?? $filePath;
                $existsFlag = file_exists($finalFilePath);
                $sizeBytes = $existsFlag ? filesize($finalFilePath) : null;
            }

            try {
                $this->persistence->insertPackageAsset(
                    $schema,
                    $recordId,
                    $fileName,
                    $existsFlag,
                    $sizeBytes
                );
                Log::debug("MIDF: asset imported from DCMK", [
                    'record_id' => $recordId,
                    'filename' => $fileName,
                ]);
            } catch (\Exception $e) {
                Log::warning("MIDF: failed to insert asset", [
                    'record_id' => $recordId,
                    'filename' => $fileName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Find media file in extraction directory.
     *
     * @param  string  $extractionPath  Extraction path
     * @param  string  $fileName  File name to find
     * @return string|null  Full path or null if not found
     */
    private function findMediaFile(string $extractionPath, string $fileName): ?string
    {
        // Search in common directories (immagini is the standard ICCD folder)
        $searchPaths = [
            $extractionPath.'/immagini', // Standard ICCD folder
            $extractionPath.'/images',
            $extractionPath.'/media',
            $extractionPath, // Root as fallback
        ];

        foreach ($searchPaths as $searchPath) {
            if (! is_dir($searchPath)) {
                continue;
            }

            // Try exact match first
            $filePath = $searchPath.'/'.$fileName;
            if (file_exists($filePath)) {
                return $filePath;
            }

            // Try case-insensitive search
            $files = glob($searchPath.'/*');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file) && strcasecmp(basename($file), $fileName) === 0) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * Copy media file to tmpPath for persistence.
     *
     * @param  ImportRun  $importRun  Import run
     * @param  string  $fileName  File name
     * @param  string  $sourcePath  Source file path
     * @return string|null  Destination path or null if copy failed
     */
    private function copyMediaFileToTmp(ImportRun $importRun, string $fileName, string $sourcePath): ?string
    {
        $tmpMediaDir = $importRun->tmpPath.'/immagini';

        if (! is_dir($tmpMediaDir)) {
            if (! mkdir($tmpMediaDir, 0755, true)) {
                Log::warning("Failed to create tmp media directory", [
                    'tmp_media_dir' => $tmpMediaDir,
                ]);
                return null;
            }
        }

        $targetPath = $tmpMediaDir.'/'.$fileName;

        // Avoid overwriting existing files
        if (file_exists($targetPath)) {
            Log::debug("Media file already exists in tmp, skipping copy", [
                'target_path' => $targetPath,
            ]);
            return $targetPath;
        }

        if (! copy($sourcePath, $targetPath)) {
            Log::warning("Failed to copy media file to tmp", [
                'source' => $sourcePath,
                'target' => $targetPath,
            ]);
            return null;
        }

        Log::debug("Media file copied to tmp", [
            'source' => $sourcePath,
            'target' => $targetPath,
        ]);

        return $targetPath;
    }

    /**
     * Get file type from extension.
     *
     * @param  string  $fileName  File name
     * @return string  File type
     */
    private function getFileType(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff' => 'image',
            'mp3', 'wav', 'ogg' => 'audio',
            'mp4', 'avi', 'mov' => 'video',
            'pdf' => 'document',
            default => 'other',
        };
    }

    /**
     * Extract normativa code from scheda XML data (CD/TSK field).
     *
     * @param  array  $scheda  Scheda data with kv_pairs
     * @return string  Normativa code (OA, S, etc.)
     */
    private function extractNormativaCode(array $scheda): string
    {
        // Look for CD/TSK in kv_pairs
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            // Match paths ending with /CD/TSK or /TSK (if CD is root)
            if (preg_match('/(\/CD\/TSK|\/TSK)$/', $key)) {
                $value = trim($pair['value'] ?? '');
                if (!empty($value)) {
                    return strtoupper($value);
                }
            }
        }

        // Default to OA if CD/TSK not found
        return 'OA';
    }

    /**
     * Estrae il valore della sezione TSK dalla scheda (es. <TSK>MI</TSK> → "MI").
     * Usato per comporre il codice identificativo SIRBEC: TSK + "_" + ACSC.
     *
     * @param  array  $scheda  Scheda data with kv_pairs
     * @return string|null  Valore TSK (trimmed) o null se non trovato
     */
    private function extractTsk(array $scheda): ?string
    {
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            if (preg_match('/(\/CD\/TSK|\/TSK)$/', $key)) {
                $value = trim((string) ($pair['value'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Risolve record_id mirror per una riga IMMFTAN.
     * - Senza RVEL (o vuoto): solo colonne record.nctr + record.nctn + import_run_id (comportamento precedente).
     * - Con RVEL: stesso filtro su record + esiste in kv una riga con path che termina in NCT/RVEL o RV/RVE/RVEL
     *   e value_text = RVEL (path da XmlParser::buildElementPath; normative diverse usano rami diversi).
     */
    private function findMirrorRecordIdForImmftan(
        string $schemaName,
        string $importRunId,
        string $nctr,
        string $nctn,
        ?string $rvel
    ): ?string {
        $nctr = trim($nctr);
        $nctn = trim($nctn);
        if ($nctr === '' || $nctn === '') {
            return null;
        }

        $rvelTrim = $rvel !== null ? trim($rvel) : '';

        if ($rvelTrim !== '') {
            // Suffisso NCT/RVEL o RV/RVE/RVEL (~*), come da struttura scheda ICCD per normativa
            $record = DB::selectOne("
                SELECT r.record_id
                FROM \"{$schemaName}\".record r
                WHERE r.nctr = ? AND r.nctn = ? AND r.import_run_id = ?
                AND EXISTS (
                    SELECT 1 FROM \"{$schemaName}\".kv k
                    WHERE k.record_id = r.record_id
                    AND k.xpath ~* '(NCT/RVEL|RV/RVE/RVEL)\$'
                    AND TRIM(COALESCE(k.value_text, '')) = ?
                )
                LIMIT 1
            ", [$nctr, $nctn, $importRunId, $rvelTrim]);
        } else {
            $record = DB::selectOne("
                SELECT r.record_id
                FROM \"{$schemaName}\".record r
                WHERE r.nctr = ? AND r.nctn = ? AND r.import_run_id = ?
                LIMIT 1
            ", [$nctr, $nctn, $importRunId]);
        }

        if ($record === null) {
            return null;
        }

        $id = preg_replace('/\s+/', ' ', trim((string) ($record->record_id ?? '')));

        return $id !== '' ? $id : null;
    }

    /**
     * Extract NCTR from scheda data.
     *
     * @param  array  $scheda  Scheda data
     * @return string|null  NCTR value or null
     */
    private function extractNctr(array $scheda): ?string
    {
        // NCTR is in CD/NCT/NCTR path (or just NCT/NCTR depending on path building)
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            // Match paths ending with /NCT/NCTR or /NCTR (if CD is root)
            if (preg_match('/(\/NCT\/NCTR|\/NCTR)$/', $key)) {
                $value = trim($pair['value'] ?? '');
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Extract NCTN from scheda data.
     *
     * @param  array  $scheda  Scheda data
     * @return string|null  NCTN value or null
     */
    private function extractNctn(array $scheda): ?string
    {
        // NCTN is in CD/NCT/NCTN path (or just NCT/NCTN depending on path building)
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            // Match paths ending with /NCT/NCTN or /NCTN (if CD is root)
            if (preg_match('/(\/NCT\/NCTN|\/NCTN)$/', $key)) {
                $value = trim($pair['value'] ?? '');
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Extract title from scheda data.
     *
     * @param  array  $scheda  Scheda data
     * @return string|null  Title value or null
     */
    private function extractTitle(array $scheda): ?string
    {
        // Title is typically in SGT/SGTI or OG/SGT/SGTI path
        // Try SGTI first (titolo principale)
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            // Match paths ending with /SGT/SGTI or /SGTI
            if (preg_match('/(\/SGT\/SGTI|\/SGTI)$/', $key)) {
                $value = trim($pair['value'] ?? '');
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        // Fallback to SGTT (titolo alternativo)
        foreach ($scheda['kv_pairs'] ?? [] as $pair) {
            $key = $pair['key'] ?? '';
            // Match paths ending with /SGT/SGTT or /SGTT
            if (preg_match('/(\/SGT\/SGTT|\/SGTT)$/', $key)) {
                $value = trim($pair['value'] ?? '');
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Restituisce il data provider per lo schema (mirror instance).
     * Usa il campo data_provider di mirror_instances (deprecato: institutions.data_provider).
     *
     * @param  string  $schemaName  Nome schema (mirror instance)
     * @return string|null  Data provider (SIRBEC, SIGEC, SBN, JSON) o null
     */
    private function getDataProvider(string $schemaName): ?string
    {
        if ($this->dataProvider !== null) {
            return $this->dataProvider;
        }

        $mirrorInstance = MirrorInstance::where('name', $schemaName)->first();

        if ($mirrorInstance === null) {
            Log::warning("Mirror instance not found for schema", [
                'schema' => $schemaName,
            ]);
            $this->dataProvider = null;

            return null;
        }

        // data_provider dalla mirror instance (non più da institution)
        $this->dataProvider = $mirrorInstance->data_provider
            ? trim($mirrorInstance->data_provider)
            : null;

        Log::debug("Data provider retrieved for schema (from mirror_instance)", [
            'schema' => $schemaName,
            'data_provider' => $this->dataProvider,
        ]);

        return $this->dataProvider;
    }

    /**
     * Extract ACSC (Codice SIRBeC IDK) from scheda data.
     *
     * Valido solo l'ACSC della sezione ACS in cui ACSS contiene "Codice SIRBeC IDK".
     * Se ci sono più nodi ACS (es. scheda storica con ACSC=NR e SIRBEC con ACSC=2d020-00407),
     * si considera il blocco ACS che ha ACSS = "Codice SIRBeC IDK" e si restituisce il suo ACSC.
     *
     * @param  array  $scheda  Scheda data
     * @return string|null  ACSC value or null if not found
     */
    private function extractAcsc(array $scheda): ?string
    {
        $pairs = $scheda['kv_pairs'] ?? [];
        // Path da XmlParser: senza slash iniziale (AC/ACS/ACSE, AC/ACS/ACSC, AC/ACS/ACSS)
        $isAcse = static fn (string $k): bool => preg_match('#(^|/)AC/ACS/ACSE$#', $k) === 1;
        $isAcsc = static fn (string $k): bool => preg_match('#(^|/)AC/ACS/ACSC$#', $k) === 1;
        $isAcss = static fn (string $k): bool => preg_match('#(^|/)AC/ACS/ACSS$#', $k) === 1;

        $currentAcsc = null;
        $currentAcss = null;

        foreach ($pairs as $pair) {
            $key = $pair['key'] ?? '';
            $value = trim((string) ($pair['value'] ?? ''));

            if ($isAcse($key)) {
                // Nuovo blocco ACS: azzeriamo ACSC/ACSS del blocco precedente
                $currentAcsc = null;
                $currentAcss = null;
                continue;
            }
            if ($isAcsc($key)) {
                $currentAcsc = $value;
                continue;
            }
            if ($isAcss($key)) {
                $currentAcss = $value;
                // Questo blocco ACS ha ACSS: se è "Codice SIRBeC IDK" restituiamo l'ACSC di questo blocco
                if (stripos($currentAcss, 'Codice SIRBeC IDK') !== false && $currentAcsc !== null && $currentAcsc !== '') {
                    return $currentAcsc;
                }
            }
        }

        return null;
    }
}
