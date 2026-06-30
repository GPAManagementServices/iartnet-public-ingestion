<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use RuntimeException;

enum PackageFormat: string
{
    case ICCD = 'ICCD';
    case SBN = 'SBN';
    case JSON = 'JSON';
    case UNKNOWN = 'Formato non accettato';
}

class PackageFormatDetector
{
    /**
     * Detect package format from extracted files.
     *
     * @param  string  $extractionPath  Path where ZIP was extracted
     * @param  array<string>  $xmlFiles  List of XML file paths
     * @param  array<string>  $allFiles  List of all extracted files
     * @param  string|null  $dataProvider  Data provider della mirror (SIGEC, SIRBEC, ...). Se SIGEC, è accettato il formato MIDF senza IMMFTAN.
     * @return PackageFormat  Detected format
     */
    public function detectFormat(string $extractionPath, array $xmlFiles, array $allFiles, ?string $dataProvider = null): PackageFormat
    {
        // Check for ICCD format (INFORMA + ICCD data files; IMMFTAN richiesto tranne per SIGEC/MIDF)
        if ($this->isIccdFormat($xmlFiles, $dataProvider)) {
            Log::info("Package format detected: ICCD", ['extraction_path' => $extractionPath]);
            return PackageFormat::ICCD;
        }

        // Check for SBN format (MARC21 XML files)
        if ($this->isSbnFormat($xmlFiles)) {
            Log::info("Package format detected: SBN", ['extraction_path' => $extractionPath]);
            return PackageFormat::SBN;
        }

        // Check for JSON format (Dublin Core JSON files)
        if ($this->isJsonFormat($allFiles)) {
            Log::info("Package format detected: JSON", ['extraction_path' => $extractionPath]);
            return PackageFormat::JSON;
        }

        Log::warning("Package format not recognized", ['extraction_path' => $extractionPath]);
        return PackageFormat::UNKNOWN;
    }

    /**
     * Check if package is ICCD format.
     *
     * ICCD format requirements:
     * - Contains INFORMA.xml
     * - Contains IMMFTAN.xml (opzionale se dataProvider = SIGEC: caso MIDF)
     * - Contains at least one ICCD data file (pattern: Tipo + CodiceEnte + TipoScheda.xml)
     *
     * @param  array<string>  $xmlFiles  List of XML file paths
     * @param  string|null  $dataProvider  Se SIGEC, IMMFTAN non è obbligatorio (MIDF).
     * @return bool
     */
    private function isIccdFormat(array $xmlFiles, ?string $dataProvider = null): bool
    {
        $fileNames = array_map('basename', $xmlFiles);
        $fileNamesLower = array_map('strtolower', $fileNames);

        $hasInforma = in_array('informa.xml', $fileNamesLower, true);
        $hasImmftan = in_array('immftan.xml', $fileNamesLower, true);
        $allowMidf = strtoupper(trim($dataProvider ?? '')) === 'SIGEC';

        Log::debug("ICCD format check: Required files", [
            'has_informa' => $hasInforma,
            'has_immftan' => $hasImmftan,
            'allow_midf' => $allowMidf,
            'xml_files' => $fileNames,
        ]);

        // Check for ICCD data files (pattern: Tipo + CodiceEnte + TipoScheda.xml)
        // Pattern: SAI652OA.xml or SAI652S.xml (Tipo: S|A, CodiceEnte: alphanumeric, TipoScheda: 1-2 letters like OA, S, etc)
        // TipoScheda can be 1 or 2 letters: OA (opera d'arte), S (stampe), etc.
        $hasIccdDataFiles = false;
        $matchedFiles = [];
        foreach ($fileNames as $fileName) {
            // Pattern: [SA] + CodiceEnte + TipoScheda (1-2 letters) + .xml
            // Example: SAI652OA.xml, SAI652S.xml, AAI652OA.xml, etc.
            if (preg_match('/^[SA]\w+[A-Z]{1,2}\.xml$/i', $fileName)) {
                $hasIccdDataFiles = true;
                $matchedFiles[] = $fileName;
            }
        }

        Log::debug("ICCD format check: Data files", [
            'has_iccd_data_files' => $hasIccdDataFiles,
            'matched_files' => $matchedFiles,
            'all_xml_files' => $fileNames,
        ]);

        // SIGEC/MIDF: IMMFTAN opzionale; altrimenti richiesto
        $isIccd = $hasInforma && ($hasImmftan || $allowMidf) && $hasIccdDataFiles;

        if (!$isIccd) {
            Log::info("ICCD format check failed", [
                'has_informa' => $hasInforma,
                'has_immftan' => $hasImmftan,
                'allow_midf' => $allowMidf,
                'has_iccd_data_files' => $hasIccdDataFiles,
                'missing_informa' => !$hasInforma,
                'missing_immftan' => !$hasImmftan,
                'missing_data_files' => !$hasIccdDataFiles,
                'xml_files_checked' => $fileNames,
            ]);
        }

        return $isIccd;
    }

    /**
     * Check if package is SBN format (MARC21 XML).
     *
     * SBN format requirements:
     * - Contains one or more XML files
     * - XML files contain MARC21 tags (<marc:...>)
     * - At least one file contains <marc:controlfield tag="001">
     *
     * @param  array<string>  $xmlFiles  List of XML file paths
     * @return bool
     */
    private function isSbnFormat(array $xmlFiles): bool
    {
        if (empty($xmlFiles)) {
            return false;
        }

        // Check each XML file for MARC21 structure
        foreach ($xmlFiles as $xmlFile) {
            if (! file_exists($xmlFile)) {
                continue;
            }

            try {
                $content = file_get_contents($xmlFile);
                if ($content === false) {
                    continue;
                }

                // Check for MARC21 namespace and controlfield tag="001"
                if (str_contains($content, '<marc:') && str_contains($content, '<marc:controlfield tag="001">')) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check XML file for SBN format", [
                    'file' => $xmlFile,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return false;
    }

    /**
     * Check if package is JSON format (Dublin Core).
     *
     * JSON format requirements:
     * - Contains one or more JSON files
     * - JSON files contain Dublin Core fields
     * - At least one file contains "Titolo" and "Autore o Creatore" fields
     *
     * @param  array<string>  $allFiles  List of all extracted files
     * @return bool
     */
    private function isJsonFormat(array $allFiles): bool
    {
        $jsonFiles = array_filter($allFiles, function ($file) {
            return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json';
        });

        if (empty($jsonFiles)) {
            return false;
        }

        // Check each JSON file for Dublin Core structure
        foreach ($jsonFiles as $jsonFile) {
            if (! file_exists($jsonFile)) {
                continue;
            }

            try {
                $content = file_get_contents($jsonFile);
                if ($content === false) {
                    continue;
                }

                $data = json_decode($content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::debug("JSON decode error", [
                        'file' => basename($jsonFile),
                        'error' => json_last_error_msg(),
                    ]);
                    continue;
                }

                // Handle array of records
                if (is_array($data) && isset($data[0]) && is_array($data[0])) {
                    // This is an array of records, check each record
                    foreach ($data as $record) {
                        if (!is_array($record)) {
                            continue;
                        }
                        
                        // Check for required Dublin Core fields
                        $hasTitolo = isset($record['Titolo']) || isset($record['titolo']);
                        $hasAutore = isset($record['Autore o Creatore']) || 
                                     isset($record['autore o creatore']) || 
                                     isset($record['Autore']) || 
                                     isset($record['autore']);
                        
                        if ($hasTitolo && $hasAutore) {
                            Log::debug("JSON format detected: array of records with required fields", [
                                'file' => basename($jsonFile),
                            ]);
                            return true;
                        }
                    }
                } elseif (is_array($data)) {
                    // This is a single record object
                    // Check for required Dublin Core fields
                    $hasTitolo = isset($data['Titolo']) || isset($data['titolo']);
                    $hasAutore = isset($data['Autore o Creatore']) || 
                                 isset($data['autore o creatore']) || 
                                 isset($data['Autore']) || 
                                 isset($data['autore']);
                    
                    if ($hasTitolo && $hasAutore) {
                        Log::debug("JSON format detected: single record with required fields", [
                            'file' => basename($jsonFile),
                        ]);
                        return true;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check JSON file for Dublin Core format", [
                    'file' => $jsonFile,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        return false;
    }
}
