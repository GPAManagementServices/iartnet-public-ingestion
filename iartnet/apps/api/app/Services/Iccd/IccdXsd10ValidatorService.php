<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use App\Data\Iccd\ValidationIssue;
use DOMDocument;
use Illuminate\Support\Facades\Log;
use libXMLError;
use RuntimeException;

/**
 * ICCD XSD 1.0 Validator Service.
 *
 * Validates XML files against XSD 1.0 schemas using PHP native DOMDocument and libxml.
 * This service provides structural validation (structure, namespaces, types, cardinality).
 *
 * Note: XSD 1.1 features (e.g., xs:assert) are NOT supported by this validator.
 */
class IccdXsd10ValidatorService
{
    /**
     * Base path for XSD files.
     */
    private const XSD_BASE_PATH = 'storage/iccd/xsd';

    /**
     * XSD file mapping by file type/name.
     *
     * @var array<string, string>
     */
    private const XSD_MAPPING = [
        'OA' => 'ICCD_OA_3.00_062018.xsd',
        'S' => 'ICCD_S_3.00.xsd',
        'INFORMA.XML' => 'informa.xsd',
        'IMMFTAN.XML' => 'immftan.xsd',
    ];

    /**
     * Validate XML file against XSD 1.0 schema.
     *
     * @param  string  $xmlPath  Path to XML file
     * @param  string  $xsdPath  Path to XSD file
     * @return array<ValidationIssue>  Array of validation issues (empty if valid)
     *
     * @throws RuntimeException If XML or XSD file not found
     */
    public function validate(string $xmlPath, string $xsdPath): array
    {
        if (! file_exists($xmlPath)) {
            throw new RuntimeException("XML file not found: {$xmlPath}");
        }

        if (! file_exists($xsdPath)) {
            throw new RuntimeException("XSD file not found: {$xsdPath}");
        }

        Log::info("Running XSD 1.0 validation", [
            'xml' => $xmlPath,
            'xsd' => $xsdPath,
        ]);

        // Enable libxml error handling
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // Load XML document
            $dom = new DOMDocument();
            
            // Load XML with error handling
            $loaded = @$dom->load($xmlPath);
            
            if (! $loaded) {
                // XML parsing errors
                return $this->parseLibXmlErrors($xmlPath);
            }

            // Validate against XSD schema
            $valid = @$dom->schemaValidate($xsdPath);

            if ($valid) {
                // No validation errors
                return [];
            }

            // Schema validation errors
            return $this->parseLibXmlErrors($xmlPath);

        } catch (\Exception $e) {
            Log::error("XSD validation exception", [
                'xml' => $xmlPath,
                'xsd' => $xsdPath,
                'error' => $e->getMessage(),
            ]);

            return [
                new ValidationIssue(
                    file: basename($xmlPath),
                    severity: 'error',
                    message: "Validation exception: ".$e->getMessage(),
                ),
            ];
        } finally {
            // Restore default error handling
            libxml_use_internal_errors(false);
        }
    }

    /**
     * Get XSD path for a given XML file.
     *
     * @param  string  $xmlPath  Path to XML file
     * @return string|null  XSD path or null if not found
     */
    public function getXsdPathForXml(string $xmlPath): ?string
    {
        $fileName = basename($xmlPath);
        $fileNameUpper = strtoupper($fileName);

        // Check for exact matches first (INFORMA, IMMFTAN)
        if (isset(self::XSD_MAPPING[$fileNameUpper])) {
            $xsdFile = self::XSD_MAPPING[$fileNameUpper];
            $xsdPath = base_path(self::XSD_BASE_PATH.'/'.$xsdFile);

            if (file_exists($xsdPath)) {
                Log::info("XSD mapping found (exact match)", [
                    'xml' => $fileName,
                    'xsd' => $xsdFile,
                ]);

                return $xsdPath;
            }
        }

        // Check for ICCD data file pattern: Tipo + CodiceEnte + TipoScheda.xml
        // Pattern: S|A + CodiceEnte + OA|S|... + .xml
        if (preg_match('/^([SA])(\w+)([A-Z]{2})\.xml$/i', $fileName, $matches)) {
            $tipo = strtoupper($matches[1]);
            $tipoScheda = strtoupper($matches[3]);

            // Map TipoScheda to XSD
            $xsdKey = null;
            if ($tipoScheda === 'OA') {
                $xsdKey = 'OA';
            } elseif ($tipoScheda === 'S') {
                $xsdKey = 'S';
            }

            if ($xsdKey !== null && isset(self::XSD_MAPPING[$xsdKey])) {
                $xsdFile = self::XSD_MAPPING[$xsdKey];
                $xsdPath = base_path(self::XSD_BASE_PATH.'/'.$xsdFile);

                if (file_exists($xsdPath)) {
                    Log::info("XSD mapping found (pattern match)", [
                        'xml' => $fileName,
                        'tipo' => $tipo,
                        'tipo_scheda' => $tipoScheda,
                        'xsd' => $xsdFile,
                    ]);

                    return $xsdPath;
                }
            }
        }

        Log::warning("No XSD mapping found for XML file", [
            'xml' => $fileName,
        ]);

        return null;
    }

    /**
     * Parse libxml errors and convert to ValidationIssue array.
     *
     * @param  string  $xmlPath  Path to XML file
     * @return array<ValidationIssue>
     */
    private function parseLibXmlErrors(string $xmlPath): array
    {
        $errors = libxml_get_errors();
        $issues = [];
        $fileName = basename($xmlPath);

        foreach ($errors as $error) {
            $severity = $this->mapLibXmlLevelToSeverity($error->level);
            $message = trim($error->message);

            // Clean up error message (remove file path, normalize whitespace)
            $message = $this->cleanErrorMessage($message);

            $issues[] = new ValidationIssue(
                file: $fileName,
                severity: $severity,
                message: $message,
                line: $error->line > 0 ? $error->line : null,
                column: $error->column > 0 ? $error->column : null,
            );
        }

        libxml_clear_errors();

        return $issues;
    }

    /**
     * Map libxml error level to severity string.
     *
     * @param  int  $level  libxml error level
     * @return string  'error' | 'warning'
     */
    private function mapLibXmlLevelToSeverity(int $level): string
    {
        return match ($level) {
            LIBXML_ERR_WARNING => 'warning',
            LIBXML_ERR_ERROR => 'error',
            LIBXML_ERR_FATAL => 'error',
            default => 'error',
        };
    }

    /**
     * Clean and normalize error message.
     *
     * @param  string  $message  Raw error message
     * @return string  Cleaned error message
     */
    private function cleanErrorMessage(string $message): string
    {
        // Remove file path references
        $message = preg_replace('/^[^:]+:\s*/', '', $message);

        // Remove namespace prefixes that clutter the message
        $message = preg_replace('/\b\w+:/', '', $message);

        // Normalize whitespace
        $message = preg_replace('/\s+/', ' ', $message);
        $message = trim($message);

        // Remove common prefixes
        $message = preg_replace('/^(Element|Attribute|Content|Schema)\s+/i', '', $message);

        return $message;
    }

    /**
     * Validate all XML files in a directory.
     *
     * @param  array<string>  $xmlFiles  Array of XML file paths
     * @return array<string, array<ValidationIssue>>  Map of file path to issues
     */
    public function validateMultiple(array $xmlFiles): array
    {
        $results = [];

        foreach ($xmlFiles as $xmlPath) {
            $xsdPath = $this->getXsdPathForXml($xmlPath);

            if ($xsdPath === null) {
                $results[$xmlPath] = [
                    new ValidationIssue(
                        file: basename($xmlPath),
                        severity: 'warning',
                        message: 'No XSD mapping found for this file type',
                    ),
                ];
                continue;
            }

            try {
                $issues = $this->validate($xmlPath, $xsdPath);
                $results[$xmlPath] = $issues;
            } catch (\Exception $e) {
                $results[$xmlPath] = [
                    new ValidationIssue(
                        file: basename($xmlPath),
                        severity: 'error',
                        message: "Validation exception: ".$e->getMessage(),
                    ),
                ];
            }
        }

        return $results;
    }
}
