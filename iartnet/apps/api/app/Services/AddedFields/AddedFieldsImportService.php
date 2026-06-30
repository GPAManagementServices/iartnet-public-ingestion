<?php

declare(strict_types=1);

namespace App\Services\AddedFields;

use App\Models\MirrorInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\SimpleExcel\SimpleExcelReader;

class AddedFieldsImportService
{
    /**
     * Import added fields from Excel file into added_kv table.
     * Header e field_name sono letti dal file Excel (riga 2 per SBN, riga 1 per le altre tipologie).
     * I file di mapping in storage/addedFields non sono più usati.
     *
     * @param  string  $excelFilePath  Path to Excel file
     * @param  string  $targetSchema  Target schema name
     * @param  string  $templateKey  Template key (usato solo per log; non per mapping)
     * @param  array<string, string>|null  $columnMap  Ignorato (deprecato)
     * @param  string|null  $extractionPath  Path radice estrazione zip (solo per upload zip)
     * @param  array<string>|null  $imageFilenames  Basename immagini nello zip, per colonna "Nome file immagine"
     * @return array{imported: int, skipped: int, errors: array, imported_record_ids: array}
     */
    public function importFields(
        string $excelFilePath,
        string $targetSchema,
        string $templateKey,
        ?array $columnMap = null,
        ?string $extractionPath = null,
        ?array $imageFilenames = null
    ): array {
        $dataProvider = $this->getDataProvider($targetSchema);

        // Header: riga 2 (index 1) per SBN, riga 1 (index 0) per le altre tipologie
        $headerRow = (strtoupper(trim($dataProvider ?? '')) === 'SBN') ? 1 : 0;
        $dataStartRow = $headerRow + 1;

        // Leggi header dal file Excel e determina la colonna record_id per nome
        $headerNames = $this->readHeaderRow($excelFilePath, $headerRow);
        $recordIdColumnName = $this->getRecordIdColumnNameByProvider($dataProvider, $headerNames);
        $keyColumnIndex = $this->findColumnIndexByHeaderName($headerNames, $recordIdColumnName);

        if ($keyColumnIndex === null) {
            Log::error("Added fields import: colonna record_id non trovata nell'header", [
                'record_id_column_name' => $recordIdColumnName,
                'headers' => $headerNames,
                'data_provider' => $dataProvider,
            ]);
            throw new \RuntimeException(
                "Colonna '{$recordIdColumnName}' (record_id) non trovata nell'header del file Excel. Header presenti: ".implode(', ', array_slice($headerNames, 0, 20)).(count($headerNames) > 20 ? '...' : '')
            );
        }

        // SIRBEC/SIGEC + NCT_RVEL: fallback su colonna IDK (match esatto record_id) se NCT_RVEL vuota
        $dpUpper = $dataProvider !== null ? strtoupper(trim($dataProvider)) : '';
        $sirbecSigecNctRvelPath = ($dpUpper === 'SIRBEC' || $dpUpper === 'SIGEC')
            && strtoupper(trim($recordIdColumnName)) === 'NCT_RVEL';
        $idkColumnIndex = $sirbecSigecNctRvelPath
            ? $this->findColumnIndexByHeaderName($headerNames, 'IDK')
            : null;

        // Indici colonne "Nome file immagine" (esatto) per insert in asset quando upload zip (Blocco A)
        $nomeFileImmagineColumnIndices = [];
        if ($extractionPath !== null && $imageFilenames !== null && count($imageFilenames) > 0) {
            foreach ($headerNames as $idx => $name) {
                if (trim((string) $name) === 'Nome file immagine') {
                    $nomeFileImmagineColumnIndices[] = $idx;
                }
            }
        }
        $imageFilenamesSet = $imageFilenames !== null ? array_flip($imageFilenames) : [];

        // Upload diretto Excel (senza zip): colonne il cui nome contiene "immagine" (es. "Immagine ad alta risoluzione", "Nome file immagine ad alta risoluzione")
        // Per ogni cella non vuota si inserisce record in asset senza verificare presenza file (l'immagine sarà caricata per altra via).
        $immagineColumnIndices = [];
        if ($extractionPath === null) {
            foreach ($headerNames as $idx => $name) {
                $n = trim((string) $name);
                if ($n !== '' && mb_stripos($n, 'immagine') !== false) {
                    $immagineColumnIndices[] = $idx;
                }
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $importedRecordIds = [];

        Log::info("Added fields import started", [
            'schema' => $targetSchema,
            'template' => $templateKey,
            'header_row' => $headerRow + 1,
            'data_start_row' => $dataStartRow + 1,
            'record_id_column_name' => $recordIdColumnName,
            'record_id_column_index' => $keyColumnIndex,
            'header_names_count' => count($headerNames),
        ]);

        try {
            // Verify target schema exists
            $this->verifyTargetSchema($targetSchema);

            // Read Excel file without header row to access columns by index
            // We need to skip rows until data_start_row
            // For ICCD: skip row 0 (header), start from row 1 (data)
            // For SBN: skip rows 0-1, start from row 2 (data)
            $reader = SimpleExcelReader::create($excelFilePath)
                ->noHeaderRow()
                ->skip($dataStartRow) // Skip rows until data starts
                ->getRows();

            $excelRowNumber = $dataStartRow; // Track Excel row number (0-based, will be incremented)

            foreach ($reader as $row) {
                $excelRowNumber++; // Increment to 1-based for user display

                Log::debug("Processing Excel row", [
                    'excel_row' => $excelRowNumber,
                    'row_data' => $row,
                ]);

                // Get values as array (in order A, B, C...)
                $rowValues = is_array($row) ? array_values($row) : [];

                Log::debug("Row values extracted", [
                    'excel_row' => $excelRowNumber,
                    'row_values_count' => count($rowValues),
                    'row_values' => $rowValues,
                ]);

                // Check if key column is populated (colonna record_id determinata per nome dall'header)
                $excelRecordId = isset($rowValues[$keyColumnIndex]) ? trim((string) $rowValues[$keyColumnIndex]) : '';
                $excelIdkValue = ($idkColumnIndex !== null && isset($rowValues[$idkColumnIndex]))
                    ? trim((string) $rowValues[$idkColumnIndex])
                    : '';
                $usedIdkAsKey = false;

                if ($excelRecordId === '') {
                    if ($sirbecSigecNctRvelPath && $excelIdkValue !== '') {
                        $usedIdkAsKey = true;
                    } else {
                        Log::debug("Skipping row - key column empty", [
                            'excel_row' => $excelRowNumber,
                            'key_column_index' => $keyColumnIndex,
                        ]);
                        $skipped++;
                        $errors[] = [
                            'row' => $excelRowNumber,
                            'key' => null,
                            'reason' => $sirbecSigecNctRvelPath
                                ? "Campo 'NCT_RVEL' vuoto e colonna 'IDK' vuota o assente"
                                : "Campo record_id '{$recordIdColumnName}' vuoto o mancante",
                        ];
                        continue;
                    }
                }

                // Risolvi record_id da usare in added_kv.
                // SIRBEC/SIGEC + NCT_RVEL → match su nctr+nctn; se NCT_RVEL vuota → IDK con match esatto su record_id.
                // CDM/BID → match per contenuto (valore contenuto in record_id).
                $recordId = $this->resolveRecordIdForAddedKv(
                    $targetSchema,
                    $dataProvider,
                    $recordIdColumnName,
                    $excelRecordId,
                    $usedIdkAsKey ? $excelIdkValue : null
                );
                if ($recordId === null) {
                    $lookupKey = $usedIdkAsKey ? $excelIdkValue : $excelRecordId;
                    Log::debug("Skipping row - no record found", [
                        'excel_row' => $excelRowNumber,
                        'excel_record_id' => $lookupKey,
                        'used_idk_as_key' => $usedIdkAsKey,
                        'data_provider' => $dataProvider,
                    ]);
                    $skipped++;
                    $errors[] = [
                        'row' => $excelRowNumber,
                        'key' => $lookupKey,
                        'reason' => $usedIdkAsKey
                            ? "Nessun record nella tabella record con record_id uguale a '{$lookupKey}'"
                            : (in_array(strtoupper(trim($dataProvider ?? '')), ['SIRBEC', 'SIGEC'], true)
                                ? "Nessun record nella tabella record con nctr+nctn corrispondente a '{$lookupKey}'"
                                : "Nessun record nella tabella record contiene l'ID '{$lookupKey}'"),
                    ];
                    continue;
                }

                $skipColumnIndices = [$keyColumnIndex];
                if ($usedIdkAsKey && $idkColumnIndex !== null) {
                    $skipColumnIndices[] = $idkColumnIndex;
                }

                // Process each field: field_name = nome colonna dall'header, valore dalla riga
                $recordHasFields = false;

                foreach ($rowValues as $index => $value) {
                    if (in_array($index, $skipColumnIndices, true)) {
                        continue; // Colonne chiave record, non vanno in added_kv
                    }

                    $fieldName = isset($headerNames[$index]) ? trim((string) $headerNames[$index]) : '';
                    if ($fieldName === '') {
                        continue; // Nessun header per questa colonna
                    }

                    $valueStr = trim((string) $value);
                    if (empty($valueStr) && $valueStr !== '0') {
                        continue; // Solo campi valorizzati (ammesso '0')
                    }

                    // Upsert added_kv: se esiste già (record_id, field_name) → update value_text e promoted = false, altrimenti insert
                    try {
                        $existing = DB::selectOne(
                            "SELECT id FROM \"{$targetSchema}\".added_kv WHERE record_id = ? AND field_name = ?",
                            [$recordId, $fieldName]
                        );

                        if ($existing !== null) {
                            DB::statement(
                                "UPDATE \"{$targetSchema}\".added_kv SET value_text = ?, promoted = false WHERE record_id = ? AND field_name = ?",
                                [$valueStr, $recordId, $fieldName]
                            );
                            Log::debug("Updated added_kv record", [
                                'record_id' => $recordId,
                                'field_name' => $fieldName,
                                'value_text' => $valueStr,
                            ]);
                        } else {
                            DB::statement("
                                INSERT INTO \"{$targetSchema}\".added_kv (record_id, field_name, value_text)
                                VALUES (?, ?, ?)
                            ", [
                                $recordId,
                                $fieldName,
                                $valueStr,
                            ]);
                            Log::debug("Inserted added_kv record", [
                                'record_id' => $recordId,
                                'field_name' => $fieldName,
                                'value_text' => $valueStr,
                            ]);
                        }

                        $imported++;
                        $recordHasFields = true;
                    } catch (\Exception $e) {
                        Log::error("Failed to upsert added_kv record", [
                            'schema' => $targetSchema,
                            'record_id' => $recordId,
                            'field_name' => $fieldName,
                            'error' => $e->getMessage(),
                        ]);

                        $skipped++;
                        $errors[] = [
                            'row' => $excelRowNumber,
                            'key' => $recordId,
                            'field' => $fieldName,
                            'reason' => "Errore inserimento/aggiornamento: {$e->getMessage()}",
                        ];
                    }
                }

                // Colonne "Nome file immagine": se valore = nome file immagine nello zip → insert in asset (solo con upload zip, Blocco A)
                if (count($nomeFileImmagineColumnIndices) > 0 && $extractionPath !== null) {
                    $this->insertAssetsForNomeFileImmagine(
                        $targetSchema,
                        $recordId,
                        $rowValues,
                        $nomeFileImmagineColumnIndices,
                        $imageFilenamesSet,
                        $extractionPath
                    );
                }

                // Upload diretto Excel: colonne il cui nome contiene "immagine" → insert in asset senza verificare presenza file
                if (count($immagineColumnIndices) > 0 && $extractionPath === null) {
                    $this->insertAssetsForImmagineColumns(
                        $targetSchema,
                        $recordId,
                        $rowValues,
                        $immagineColumnIndices
                    );
                }

                // Track record ID if at least one field was imported
                if ($recordHasFields && ! in_array($recordId, $importedRecordIds, true)) {
                    $importedRecordIds[] = $recordId;
                }
            }

            Log::info("Added fields import completed", [
                'schema' => $targetSchema,
                'template' => $templateKey,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors_count' => count($errors),
                'imported_record_ids_count' => count($importedRecordIds),
            ]);

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'imported_record_ids' => $importedRecordIds,
            ];
        } catch (\Exception $e) {
            Log::error("Added fields import failed", [
                'schema' => $targetSchema,
                'template' => $templateKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify target schema exists.
     *
     * @param  string  $schemaName  Schema name
     * @return void
     *
     * @throws \RuntimeException If schema does not exist
     */
    private function verifyTargetSchema(string $schemaName): void
    {
        $exists = DB::selectOne(
            "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
            [$schemaName]
        );

        if (! ($exists->exists ?? false)) {
            throw new \RuntimeException("Schema '{$schemaName}' does not exist.");
        }

        // Verify added_kv table exists
        $tableExists = DB::selectOne(
            "SELECT EXISTS(
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = ? 
                AND table_name = 'added_kv'
            ) as exists",
            [$schemaName]
        );

        if (! ($tableExists->exists ?? false)) {
            throw new \RuntimeException("Table '{$schemaName}.added_kv' does not exist.");
        }
    }

    /**
     * Risolve il record_id da usare per l'inserimento in added_kv.
     * - Colonna CDM o BID (anche con mirror SIGEC/tipo OA): match se record_id contiene il valore (scheda MIDF).
     * - SIRBEC/SIGEC + colonna NCT_RVEL: match su nctr+nctn (concatenazione = valore Excel).
     * - SIRBEC/SIGEC + NCT_RVEL vuota + IDK valorizzato: match esatto su record_id.
     * - Altri: match per contenuto (record_id contiene il valore letto).
     *
     * @param  string  $schemaName  Nome schema (mirror)
     * @param  string|null  $dataProvider  Data provider (SBN, SIRBEC, SIGEC, ...)
     * @param  string  $recordIdColumnName  Nome colonna usata (CDM, NCT_RVEL, BID, ...)
     * @param  string  $excelValue  Valore letto dalla colonna chiave (NCT_RVEL, CDM, BID, ...)
     * @param  string|null  $idkFallbackValue  Valore colonna IDK (fallback SIRBEC/SIGEC se NCT_RVEL vuota)
     * @return string|null  record_id della tabella record da usare, o null se nessun match
     */
    private function resolveRecordIdForAddedKv(
        string $schemaName,
        ?string $dataProvider,
        string $recordIdColumnName,
        string $excelValue,
        ?string $idkFallbackValue = null
    ): ?string {
        $columnUpper = strtoupper(trim($recordIdColumnName));
        $dp = $dataProvider !== null ? strtoupper(trim($dataProvider)) : '';

        // CDM (e BID): anche con SIGEC/tipo OA, match per contenuto (valore CDM contenuto in record_id, scheda MIDF)
        if ($columnUpper === 'CDM' || $columnUpper === 'BID') {
            return $this->findRecordIdContaining($schemaName, $excelValue);
        }

        if ($dp === 'SIRBEC' || $dp === 'SIGEC') {
            if (trim($excelValue) !== '') {
                return $this->findRecordIdByNctrNctn($schemaName, $excelValue);
            }
            if ($idkFallbackValue !== null && trim($idkFallbackValue) !== '') {
                return $this->findRecordIdByExactMatch($schemaName, $idkFallbackValue);
            }

            return null;
        }

        return $this->findRecordIdContaining($schemaName, $excelValue);
    }

    /**
     * Cerca nella tabella record tramite la colonna NCT_RVEL dell'Excel.
     * Nel foglio Excel NCT_RVEL è un unico codice (es. 0900254706). Il confronto si fa con
     * la concatenazione nctr + nctn della tabella record (stesso formato unico).
     *
     * @param  string  $schemaName  Nome schema (mirror)
     * @param  string  $nctRvelValue  Codice unico letto dalla colonna NCT_RVEL (es. 0900254706)
     * @return string|null  record_id della tabella record, o null se non trovato
     */
    private function findRecordIdByNctrNctn(string $schemaName, string $nctRvelValue): ?string
    {
        $code = trim($nctRvelValue);
        if ($code === '') {
            return null;
        }

        try {
            // Confronto: valore Excel = concatenazione nctr + nctn nella tabella record
            $row = DB::selectOne(
                "SELECT record_id FROM \"{$schemaName}\".record 
                 WHERE (TRIM(COALESCE(nctr, '')) || TRIM(COALESCE(nctn, ''))) = ? 
                 LIMIT 1",
                [$code]
            );

            return $row !== null ? (string) $row->record_id : null;
        } catch (\Exception $e) {
            Log::warning("Error finding record_id by NCT_RVEL (nctr||nctn)", [
                'schema' => $schemaName,
                'nct_rvel_value' => $code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cerca un record con record_id uguale al valore letto dall'Excel (match esatto).
     * Usato per fallback IDK su mirror SIRBEC/SIGEC quando NCT_RVEL è vuota.
     *
     * @param  string  $schemaName  Nome schema (mirror)
     * @param  string  $excelRecordId  Valore letto dalla colonna IDK
     * @return string|null  record_id della tabella record da usare, o null se nessun match
     */
    private function findRecordIdByExactMatch(string $schemaName, string $excelRecordId): ?string
    {
        $code = preg_replace('/\s+/', ' ', trim($excelRecordId));
        if ($code === '') {
            return null;
        }

        try {
            $row = DB::selectOne(
                "SELECT record_id FROM \"{$schemaName}\".record WHERE record_id = ? LIMIT 1",
                [$code]
            );

            return $row !== null ? (string) $row->record_id : null;
        } catch (\Exception $e) {
            Log::warning("Error finding record_id by exact match (IDK)", [
                'schema' => $schemaName,
                'excel_record_id' => $code,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cerca un record il cui record_id contiene il valore letto dall'Excel (es. CDM o BID).
     * Usato quando il codice in Excel è una parte del record_id: match con WHERE record_id LIKE %valore%.
     *
     * @param  string  $schemaName  Nome schema (mirror)
     * @param  string  $excelRecordId  Valore letto (es. CDM: parte del codice; match se contenuto in record_id)
     * @return string|null  record_id della tabella record da usare, o null se nessun match
     */
    private function findRecordIdContaining(string $schemaName, string $excelRecordId): ?string
    {
        try {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $excelRecordId);
            $pattern = '%'.$escaped.'%';

            $row = DB::selectOne(
                "SELECT record_id FROM \"{$schemaName}\".record WHERE record_id LIKE ? LIMIT 1",
                [$pattern]
            );

            return $row !== null ? (string) $row->record_id : null;
        } catch (\Exception $e) {
            Log::warning("Error finding record_id containing excel value", [
                'schema' => $schemaName,
                'excel_record_id' => $excelRecordId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if record exists in record table.
     *
     * @param  string  $schemaName  Schema name
     * @param  string  $recordId  Record ID
     * @return bool  True if record exists
     */
    private function recordExists(string $schemaName, string $recordId): bool
    {
        try {
            $exists = DB::selectOne(
                "SELECT EXISTS(
                    SELECT 1 FROM \"{$schemaName}\".record 
                    WHERE record_id = ?
                ) as exists",
                [$recordId]
            );

            return (bool) ($exists->exists ?? false);
        } catch (\Exception $e) {
            Log::warning("Error checking record existence", [
                'schema' => $schemaName,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get imported records for a given record_id list.
     *
     * @param  string  $targetSchema  Target schema name
     * @param  array<string>  $recordIds  List of record IDs
     * @return array  Imported records
     */
    public function getImportedRecords(string $targetSchema, array $recordIds): array
    {
        if (empty($recordIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));

            $records = DB::select(
                "SELECT record_id, field_name, value_text 
                 FROM \"{$targetSchema}\".added_kv 
                 WHERE record_id IN ({$placeholders})
                 ORDER BY record_id, field_name",
                $recordIds
            );

            return array_map(function ($record) {
                return [
                    'record_id' => $record->record_id,
                    'field_name' => $record->field_name,
                    'value_text' => $record->value_text,
                ];
            }, $records);
        } catch (\Exception $e) {
            Log::error("Error fetching imported records", [
                'schema' => $targetSchema,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Legge la riga di header dal file Excel (riga 0-based).
     *
     * @param  string  $excelFilePath  Path al file Excel
     * @param  int  $headerRowIndex  Indice riga header (0-based)
     * @return array<int, string>  Header per indice colonna (trim)
     */
    private function readHeaderRow(string $excelFilePath, int $headerRowIndex): array
    {
        $rows = SimpleExcelReader::create($excelFilePath)
            ->noHeaderRow()
            ->skip($headerRowIndex)
            ->getRows();

        $headerRow = null;
        foreach ($rows as $row) {
            $headerRow = is_array($row) ? array_values($row) : [];
            break;
        }

        if (! is_array($headerRow) || empty($headerRow)) {
            return [];
        }

        return array_map(static fn ($v): string => trim((string) $v), $headerRow);
    }

    /**
     * Determina il nome della colonna record_id in base al data_provider e agli header presenti.
     * - SBN → BID
     * - SIRBEC/SIGEC: se presente CDM nell'header → CDM (scheda MIDF, match per contenuto in record_id), altrimenti NCT_RVEL (fallback IDK se NCT_RVEL vuota)
     * - Altri → NCT_RVEL se presente nell'header, altrimenti CDM
     *
     * @param  string|null  $dataProvider  Data provider (SBN, SIRBEC, SIGEC, ...)
     * @param  array<int, string>  $headerNames  Nomi colonne lette dall'header Excel
     * @return string  Nome colonna da usare come record_id
     */
    private function getRecordIdColumnNameByProvider(?string $dataProvider, array $headerNames): string
    {
        $normalized = array_map(static fn (string $h): string => strtoupper(trim($h)), $headerNames);
        $has = static fn (string $name): bool => in_array(strtoupper(trim($name)), $normalized, true);

        $dp = $dataProvider !== null ? strtoupper(trim($dataProvider)) : '';

        if ($dp === 'SBN') {
            return 'BID';
        }
        // SIRBEC/SIGEC: se l'Excel ha CDM (scheda MIDF) usare CDM e match per contenuto (valore contenuto in record_id)
        if (($dp === 'SIRBEC' || $dp === 'SIGEC') && $has('CDM')) {
            return 'CDM';
        }
        if ($dp === 'SIRBEC' || $dp === 'SIGEC') {
            return 'NCT_RVEL';
        }
        // Altri casi: NCT_RVEL o CDM, a seconda di quale è presente nell'header
        if ($has('NCT_RVEL')) {
            return 'NCT_RVEL';
        }
        if ($has('CDM')) {
            return 'CDM';
        }
        return 'NCT_RVEL'; // fallback (poi la findColumnIndex restituirà null se assente)
    }

    /**
     * Trova l'indice della colonna il cui header (normalizzato) coincide con il nome richiesto.
     *
     * @param  array<int, string>  $headerNames  Nomi colonne per indice
     * @param  string  $columnName  Nome da cercare (es. BID, IDK, NCT_RVEL, CDM)
     * @return int|null  Indice colonna o null se non trovato
     */
    private function findColumnIndexByHeaderName(array $headerNames, string $columnName): ?int
    {
        $needle = strtoupper(trim($columnName));
        foreach ($headerNames as $index => $name) {
            if (strtoupper(trim($name)) === $needle) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Per ogni colonna "Nome file immagine" con valore = basename di un'immagine nello zip, inserisce un record in asset.
     *
     * @param  array<int, mixed>  $rowValues  Valori riga Excel per indice
     * @param  array<int, int>  $nomeFileImmagineColumnIndices  Indici colonne con header "Nome file immagine"
     * @param  array<string, int>  $imageFilenamesSet  Mappa basename => 1 (per lookup)
     */
    private function insertAssetsForNomeFileImmagine(
        string $targetSchema,
        string $recordId,
        array $rowValues,
        array $nomeFileImmagineColumnIndices,
        array $imageFilenamesSet,
        string $extractionPath
    ): void {
        $insertedFilenames = [];
        foreach ($nomeFileImmagineColumnIndices as $colIndex) {
            $value = isset($rowValues[$colIndex]) ? trim((string) $rowValues[$colIndex]) : '';
            if ($value === '') {
                continue;
            }
            if (! isset($imageFilenamesSet[$value])) {
                continue;
            }
            // Evita duplicati nella stessa riga (stesso record_id + filename)
            if (in_array($value, $insertedFilenames, true)) {
                continue;
            }
            $insertedFilenames[] = $value;

            $filePath = $this->resolveImagePathInExtraction($extractionPath, $value);
            $existsFlag = $filePath !== null && file_exists($filePath);
            $sizeBytes = $existsFlag && $filePath ? filesize($filePath) : null;

            try {
                $existing = DB::selectOne(
                    "SELECT id FROM \"{$targetSchema}\".asset WHERE record_id = ? AND filename = ?",
                    [$recordId, $value]
                );
                if ($existing !== null) {
                    continue;
                }
                DB::statement("
                    INSERT INTO \"{$targetSchema}\".asset
                    (record_id, filename, exists_flag, promoted, size_bytes)
                    VALUES (?, ?, ?, false, ?)
                ", [$recordId, $value, $existsFlag, $sizeBytes]);
                Log::debug("Added fields: inserted asset", ['record_id' => $recordId, 'filename' => $value]);
            } catch (\Exception $e) {
                Log::warning("Added fields: asset insert failed", [
                    'record_id' => $recordId,
                    'filename' => $value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Upload diretto Excel (senza zip): per ogni colonna il cui header contiene "immagine", se la cella non è vuota
     * inserisce un record in asset con record_id, filename = valore cella, promoted = false.
     * Non si verifica la presenza fisica del file (l'immagine sarà caricata per altra via).
     *
     * @param  array<int, int>  $immagineColumnIndices  Indici colonne con header che contiene "immagine"
     */
    private function insertAssetsForImmagineColumns(
        string $targetSchema,
        string $recordId,
        array $rowValues,
        array $immagineColumnIndices
    ): void {
        $insertedFilenames = [];
        foreach ($immagineColumnIndices as $colIndex) {
            $value = isset($rowValues[$colIndex]) ? trim((string) $rowValues[$colIndex]) : '';
            if ($value === '') {
                continue;
            }
            // Evita duplicati nella stessa riga (stesso record_id + filename)
            if (in_array($value, $insertedFilenames, true)) {
                continue;
            }
            $insertedFilenames[] = $value;

            try {
                $existing = DB::selectOne(
                    "SELECT id FROM \"{$targetSchema}\".asset WHERE record_id = ? AND filename = ?",
                    [$recordId, $value]
                );
                if ($existing !== null) {
                    continue;
                }
                DB::statement("
                    INSERT INTO \"{$targetSchema}\".asset
                    (record_id, filename, exists_flag, promoted, size_bytes)
                    VALUES (?, ?, false, false, ?)
                ", [$recordId, $value, null]);
                Log::debug("Added fields (direct Excel): inserted asset", ['record_id' => $recordId, 'filename' => $value]);
            } catch (\Exception $e) {
                Log::warning("Added fields (direct Excel): asset insert failed", [
                    'record_id' => $recordId,
                    'filename' => $value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cerca un file per basename sotto la cartella di estrazione (ricorsivo).
     */
    private function resolveImagePathInExtraction(string $extractionPath, string $basename): ?string
    {
        if (! is_dir($extractionPath)) {
            return null;
        }
        $flat = $extractionPath.DIRECTORY_SEPARATOR.$basename;
        if (file_exists($flat) && is_file($flat)) {
            return $flat;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractionPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getFilename() === $basename) {
                return $file->getPathname();
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
        $mirrorInstance = MirrorInstance::where('name', $schemaName)->first();

        if ($mirrorInstance === null) {
            Log::warning("Mirror instance not found for schema", [
                'schema' => $schemaName,
            ]);

            return null;
        }

        // data_provider dalla mirror instance (non più da institution)
        $dataProvider = $mirrorInstance->data_provider
            ? trim($mirrorInstance->data_provider)
            : null;

        Log::debug("Data provider retrieved for schema (from mirror_instance)", [
            'schema' => $schemaName,
            'data_provider' => $dataProvider,
        ]);

        return $dataProvider;
    }
}
