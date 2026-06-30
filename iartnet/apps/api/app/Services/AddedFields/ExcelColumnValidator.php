<?php

declare(strict_types=1);

namespace App\Services\AddedFields;

use Illuminate\Support\Facades\Log;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Validazione colonne file Excel per Add Fields.
 * Non usa più i file template .txt: la validità è determinata da data_provider e presenza di colonne obbligatorie.
 */
class ExcelColumnValidator
{
    /**
     * Riga (0-based) da cui leggere l'header: SBN = riga 2 (index 1), altrimenti riga 1 (index 0).
     */
    private const HEADER_ROW_SBN = 1;

    private const HEADER_ROW_DEFAULT = 0;

    /**
     * Colonna obbligatoria per data_provider SBN.
     */
    private const REQUIRED_HEADER_SBN = 'BID';

    /**
     * Colonne obbligatorie per data_provider non-SBN: almeno una deve essere presente nell'header.
     */
    private const REQUIRED_HEADERS_NON_SBN = ['IDK', 'NCT_RVEL', 'CDM'];

    /**
     * Template key restituito quando il file è valido ma non SBN (compatibilità con import che usa getColumnMap/getTemplateRules).
     */
    private const DEFAULT_TEMPLATE_KEY_NON_SBN = 'ICCD_OA300';

    /**
     * Template files per getColumnMap / getTemplateRules (compatibilità con AddedFieldsImportService).
     * Non usati per il check di validità.
     */
    private const TEMPLATE_FILES = [
        'ICCD_OA300' => 'ICCD_OA300.txt',
        'ICCD_S300' => 'ICCD_S300.txt',
        'ICCD_FF400' => 'ICCD_FF400.txt',
        'ICCD_MIDF401' => 'ICCD_MIDF401.txt',
        'ICCD_MINV401' => 'ICCD_MINV401.txt',
        'SBN' => 'SBN.txt',
    ];

    private const TEMPLATE_RULES = [
        'ICCD_OA300' => ['header_row' => 0, 'max_columns' => 19],
        'ICCD_S300' => ['header_row' => 0, 'max_columns' => 9],
        'ICCD_FF400' => ['header_row' => 0, 'max_columns' => 8],
        'ICCD_MIDF401' => ['header_row' => 0, 'max_columns' => 8],
        'ICCD_MINV401' => ['header_row' => 0, 'max_columns' => 12],
        'SBN' => ['header_row' => 1, 'max_columns' => 18],
    ];

    /**
     * Valida il file Excel in base al data_provider selezionato.
     * Non usa più i file template .txt.
     *
     * Regole:
     * - data_provider = SBN: header letto dalla riga 2; file valido se tra le colonne c'è 'BID'.
     * - altri data_provider: header letto dalla riga 1; file valido se c'è almeno uno tra 'IDK', 'NCT_RVEL', 'CDM'.
     *
     * @param  string  $excelFilePath  Path al file Excel caricato
     * @param  string|null  $dataProvider  Tipo selezionato (SBN, SIRBEC, SIGEC, JSON, ...). Se null, si usa riga 1 e richiesta IDK/NCT_RVEL/CDM
     * @return array{valid: bool, matched_template: string|null, message: string, columns: array, column_map?: array}
     */
    public function validateColumns(string $excelFilePath, ?string $dataProvider = null): array
    {
        Log::info('ExcelColumnValidator: validateColumns called', [
            'excel_file_path' => $excelFilePath,
            'data_provider' => $dataProvider,
            'file_exists' => file_exists($excelFilePath),
        ]);

        if (! file_exists($excelFilePath)) {
            Log::error('ExcelColumnValidator: File does not exist', ['excel_file_path' => $excelFilePath]);

            return [
                'valid' => false,
                'matched_template' => null,
                'message' => 'File Excel non trovato',
                'columns' => [],
            ];
        }

        try {
            $isSbn = $this->isSbnDataProvider($dataProvider);

            // Riga header: SBN = riga 2 (index 1), altrimenti riga 1 (index 0)
            $headerRowIndex = $isSbn ? self::HEADER_ROW_SBN : self::HEADER_ROW_DEFAULT;
            $headers = $this->readHeadersAtRow($excelFilePath, $headerRowIndex);

            if (empty($headers)) {
                $rowLabel = $headerRowIndex === 1 ? 'riga 2' : 'riga 1';
                Log::warning('ExcelColumnValidator: Nessuna colonna letta', [
                    'header_row' => $headerRowIndex,
                    'data_provider' => $dataProvider,
                ]);

                return [
                    'valid' => false,
                    'matched_template' => null,
                    'message' => "Impossibile leggere l'header dalla {$rowLabel}. Verificare il file.",
                    'columns' => [],
                ];
            }

            // Normalizza per confronto (trim, maiuscolo)
            $headersNormalized = array_map(static function (string $h): string {
                return strtoupper(trim($h));
            }, $headers);

            if ($isSbn) {
                $valid = in_array(self::REQUIRED_HEADER_SBN, $headersNormalized, true);
                if ($valid) {
                    $matchedTemplate = 'SBN';
                    $message = 'File valido. Tipo: SBN (header con BID).';
                } else {
                    $matchedTemplate = null;
                    $message = "File non valido per SBN: manca la colonna obbligatoria '".self::REQUIRED_HEADER_SBN."'.";
                }
            } else {
                $found = null;
                foreach (self::REQUIRED_HEADERS_NON_SBN as $required) {
                    if (in_array($required, $headersNormalized, true)) {
                        $found = $required;
                        break;
                    }
                }
                $valid = $found !== null;
                if ($valid) {
                    $matchedTemplate = self::DEFAULT_TEMPLATE_KEY_NON_SBN;
                    $message = 'File valido. Tipo: ICCD (header con almeno uno tra IDK, NCT_RVEL, CDM).';
                } else {
                    $matchedTemplate = null;
                    $requiredList = implode(', ', self::REQUIRED_HEADERS_NON_SBN);
                    $message = "File non valido: nell'header deve essere presente almeno una delle colonne: {$requiredList}.";
                }
            }

            Log::info('ExcelColumnValidator: Validazione completata', [
                'data_provider' => $dataProvider,
                'is_sbn' => $isSbn,
                'header_row' => $headerRowIndex + 1,
                'valid' => $valid,
                'matched_template' => $matchedTemplate,
                'headers_count' => count($headers),
            ]);

            $result = [
                'valid' => $valid,
                'matched_template' => $matchedTemplate,
                'message' => $message,
                'columns' => $headers,
            ];

            if ($valid && $matchedTemplate !== null) {
                $result['column_map'] = $this->getColumnMap($matchedTemplate);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('ExcelColumnValidator: Errore durante la validazione', [
                'file' => $excelFilePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'valid' => false,
                'matched_template' => null,
                'message' => 'Errore durante la lettura del file Excel: '.$e->getMessage(),
                'columns' => [],
            ];
        }
    }

    /**
     * Indica se il data_provider è SBN (case-insensitive).
     */
    private function isSbnDataProvider(?string $dataProvider): bool
    {
        if ($dataProvider === null || $dataProvider === '') {
            return false;
        }

        return strtoupper(trim($dataProvider)) === 'SBN';
    }

    /**
     * Legge i valori della riga di header nel file Excel (riga 0-based).
     *
     * @param  string  $filePath  Path al file Excel
     * @param  int  $headerRow  Indice riga (0-based)
     * @return array<string>
     */
    private function readHeadersAtRow(string $filePath, int $headerRow): array
    {
        try {
            $reader = SimpleExcelReader::create($filePath)
                ->noHeaderRow()
                ->getRows();

            $currentRowIndex = -1;
            $headerRowData = null;

            foreach ($reader as $row) {
                $currentRowIndex++;
                if ($currentRowIndex < $headerRow) {
                    continue;
                }
                if ($currentRowIndex === $headerRow) {
                    $headerRowData = is_array($row) ? $row : [];
                    break;
                }
            }

            if (! is_array($headerRowData) || empty($headerRowData)) {
                return [];
            }

            $columns = array_values($headerRowData);

            return array_map(static function ($column): string {
                return trim((string) $column);
            }, $columns);
        } catch (\Throwable $e) {
            Log::error('ExcelColumnValidator: Errore lettura header', [
                'file' => $filePath,
                'header_row' => $headerRow,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Nome della colonna chiave in base a template e data_provider (compatibilità import).
     *
     * @param  string  $templateKey  Chiave template (ICCD_OA300, SBN, ...)
     * @param  string|null  $dataProvider  Data provider (SIRBEC, SIGEC, SBN, ...)
     * @return string
     */
    public function getKeyColumnName(string $templateKey, ?string $dataProvider = null): string
    {
        if ($dataProvider === 'SIRBEC' && in_array($templateKey, ['ICCD_OA300', 'ICCD_S300'], true)) {
            return 'IDK';
        }

        return match ($templateKey) {
            'ICCD_OA300', 'ICCD_S300', 'ICCD_FF400' => 'NCT_RVEL',
            'ICCD_MIDF401', 'ICCD_MINV401' => 'CDM',
            'SBN' => 'BID',
            default => throw new \InvalidArgumentException("Unknown template key: {$templateKey}"),
        };
    }

    /**
     * Mappa colonne per template (da file .txt, per compatibilità con import).
     *
     * @param  string  $templateKey  Chiave template
     * @return array<string, string>
     */
    public function getColumnMap(string $templateKey): array
    {
        if (! isset(self::TEMPLATE_FILES[$templateKey])) {
            throw new \InvalidArgumentException("Unknown template key: {$templateKey}");
        }

        $templatePath = storage_path('addedFields/'.self::TEMPLATE_FILES[$templateKey]);

        if (! file_exists($templatePath)) {
            Log::warning("ExcelColumnValidator: Template file not found: {$templatePath}");

            return [];
        }

        return $this->readTemplateTxtFile($templatePath);
    }

    /**
     * Regole template (riga header, max colonne) per compatibilità con import.
     *
     * @param  string  $templateKey  Chiave template
     * @return array{header_row: int, max_columns: int}
     */
    public function getTemplateRules(string $templateKey): array
    {
        if (! isset(self::TEMPLATE_RULES[$templateKey])) {
            throw new \InvalidArgumentException("Unknown template key: {$templateKey}");
        }

        return self::TEMPLATE_RULES[$templateKey];
    }

    /**
     * Legge il file template .txt e restituisce la mappa colonna lettera => nome campo.
     * Usato da getColumnMap per compatibilità con l'import.
     *
     * @param  string  $filePath  Path al file .txt
     * @return array<string, string>
     */
    private function readTemplateTxtFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $columnMap = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Column\s+([A-Z]+)\s*=\s*(.+?)(?:,)?\s*$/i', $line, $matches)) {
                $columnLetter = strtoupper(trim($matches[1]));
                $fieldName = trim($matches[2]);
                $columnMap[$columnLetter] = $fieldName;
            }
        }

        return $columnMap;
    }

    /**
     * Test lettura header di tutti i template (per verifiche).
     *
     * @return array<string, array<string, string>>
     */
    public function testReadAllTemplateHeaders(): array
    {
        $results = [];
        foreach (self::TEMPLATE_FILES as $templateKey => $templateFileName) {
            $templatePath = storage_path('addedFields/'.$templateFileName);
            $results[$templateKey] = file_exists($templatePath)
                ? $this->readTemplateTxtFile($templatePath)
                : [];
        }

        return $results;
    }
}
