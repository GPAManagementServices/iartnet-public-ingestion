<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use App\Data\Iccd\ValidationIssue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SaxonValidator
{
    /**
     * Path to Saxon HE JAR file.
     * Note: Directory name has typo "saxson" instead of "saxon" in filesystem.
     */
    private const SAXON_JAR = 'tools/saxson/saxon-he-12.9.jar';

    /**
     * Path to XMLResolver JAR file.
     */
    private const XMLRESOLVER_JAR = 'tools/saxson/lib/xmlresolver-6.0.19.jar';

    /**
     * Path to XMLResolver Data JAR file.
     */
    private const XMLRESOLVER_DATA_JAR = 'tools/saxson/lib/xmlresolver-6.0.19-data.jar';

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
     * Validate XML file against XSD 1.1 using Saxon HE.
     *
     * @param  string  $xmlPath  Path to XML file
     * @param  string  $xsdPath  Path to XSD file
     * @return array<ValidationIssue>  Array of validation issues
     *
     * @throws RuntimeException If validation process fails
     */
    public function validate(string $xmlPath, string $xsdPath): array
    {
        if (! file_exists($xmlPath)) {
            throw new RuntimeException("XML file not found: {$xmlPath}");
        }

        if (! file_exists($xsdPath)) {
            throw new RuntimeException("XSD file not found: {$xsdPath}");
        }

        $saxonJar = base_path(self::SAXON_JAR);
        $xmlresolverJar = base_path(self::XMLRESOLVER_JAR);
        $xmlresolverDataJar = base_path(self::XMLRESOLVER_DATA_JAR);

        if (! file_exists($saxonJar)) {
            throw new RuntimeException("Saxon HE JAR not found: {$saxonJar}");
        }

        if (! file_exists($xmlresolverJar)) {
            throw new RuntimeException("XMLResolver JAR not found: {$xmlresolverJar}");
        }

        if (! file_exists($xmlresolverDataJar)) {
            throw new RuntimeException("XMLResolver Data JAR not found: {$xmlresolverDataJar}");
        }

        // Build validation command for Windows using Saxon HE
        // Saxon HE doesn't have com.saxonica.Validate, so we use Transform with identity stylesheet
        // Format: java -cp "jar1;jar2;jar3" net.sf.saxon.Transform -s:file.xml -xsl:identity.xsl -xsd:schema.xsd
        // Use semicolon (;) as classpath separator on Windows
        
        // Create a temporary identity stylesheet for validation
        $identityStylesheet = $this->createIdentityStylesheet();
        
        $classpath = implode(';', [
            $saxonJar,
            $xmlresolverJar,
            $xmlresolverDataJar,
        ]);

        // Build command as string (sprintf format)
        // -s: source XML file to validate
        // -xsl: identity stylesheet (copies input to output)
        // -xsd: XSD schema file (validates during transformation)
        $command = sprintf(
            'java -cp "%s" net.sf.saxon.Transform -s:%s -xsl:%s -xsd:%s',
            $classpath,
            escapeshellarg($xmlPath),
            escapeshellarg($identityStylesheet),
            escapeshellarg($xsdPath)
        );

        Log::info("Running Saxon validation", [
            'xml' => $xmlPath,
            'xsd' => $xsdPath,
            'command' => $command,
        ]);

        try {
            // Use Process::fromShellCommandline() to properly handle the command string
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(300); // 5 minutes timeout
            $process->run();

            $issues = [];

            if (! $process->isSuccessful()) {
                $output = $process->getOutput();
                $errorOutput = $process->getErrorOutput();

                // Parse Saxon validation errors
                $issues = $this->parseSaxonOutput($xmlPath, $output.$errorOutput);
            }

            return $issues;
        } catch (ProcessFailedException $e) {
            Log::error("Saxon validation process failed", [
                'xml' => $xmlPath,
                'xsd' => $xsdPath,
                'error' => $e->getMessage(),
            ]);

            // Return a generic error issue
            return [
                new ValidationIssue(
                    file: basename($xmlPath),
                    severity: 'error',
                    message: "Validation process failed: ".$e->getMessage(),
                ),
            ];
        }
    }

    /**
     * Create a temporary identity stylesheet for XSD validation.
     * Saxon HE requires a stylesheet even for validation, so we use an identity transform.
     *
     * @return string Path to the identity stylesheet file
     */
    private function createIdentityStylesheet(): string
    {
        $stylesheetDir = storage_path('app/iccd/tmp');
        if (! is_dir($stylesheetDir)) {
            mkdir($stylesheetDir, 0755, true);
        }

        $stylesheetPath = $stylesheetDir.'/identity.xsl';

        // Identity stylesheet: copies input XML to output (used for validation)
        $stylesheet = '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="3.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    <xsl:output method="xml" indent="yes"/>
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>
</xsl:stylesheet>';

        file_put_contents($stylesheetPath, $stylesheet);

        return $stylesheetPath;
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
     * Parse Saxon validation output to extract issues.
     *
     * @param  string  $xmlPath  Path to XML file
     * @param  string  $output  Saxon output
     * @return array<ValidationIssue>
     */
    private function parseSaxonOutput(string $xmlPath, string $output): array
    {
        $issues = [];
        $fileName = basename($xmlPath);

        // Saxon XSD validation errors format examples:
        // "Error on line 10 column 5 of file.xml: cvc-complex-type.2.4.a: Invalid content was found..."
        // "Validation error: ..."
        // "Error: ..."

        if (empty(trim($output))) {
            return $issues;
        }

        $lines = explode("\n", $output);
        $currentMessage = '';
        $lineNum = null;
        $columnNum = null;
        $severity = 'error';

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and common Saxon info messages
            if (empty($line) || 
                stripos($line, 'Saxon') !== false && stripos($line, 'version') !== false ||
                stripos($line, 'Processing') !== false) {
                continue;
            }

            // Extract line number: "Error on line 10 column 5" or "line 10"
            if (preg_match('/line\s+(\d+)/i', $line, $matches)) {
                $lineNum = (int) $matches[1];
            }

            // Extract column number: "column 5" or "column 5 of"
            if (preg_match('/column\s+(\d+)/i', $line, $matches)) {
                $columnNum = (int) $matches[1];
            }

            // Determine severity
            if (stripos($line, 'warning') !== false) {
                $severity = 'warning';
            } elseif (stripos($line, 'info') !== false || stripos($line, 'note') !== false) {
                $severity = 'info';
            } else {
                $severity = 'error';
            }

            // Clean up common prefixes
            $cleanLine = $line;
            $cleanLine = preg_replace('/^Error\s*(on\s*)?/i', '', $cleanLine);
            $cleanLine = preg_replace('/^Validation\s+error\s*:?\s*/i', '', $cleanLine);
            $cleanLine = preg_replace('/^.*?:\s*/', '', $cleanLine); // Remove "file.xml: " prefix

            // Skip if line is just a file path reference
            if (preg_match('/^[A-Z]:\\\/', $cleanLine) || preg_match('/^\/[^:]+$/', $cleanLine)) {
                continue;
            }

            // Build message - accumulate multi-line errors
            if (! empty($cleanLine)) {
                if (empty($currentMessage)) {
                    $currentMessage = $cleanLine;
                } else {
                    $currentMessage .= ' '.$cleanLine;
                }
            }

            // If line ends with a period or is a complete error, create issue
            if (preg_match('/[.:]$/', $line) || empty($cleanLine)) {
                if (! empty($currentMessage)) {
                    // Clean up message
                    $message = trim($currentMessage);
                    $message = preg_replace('/\s+/', ' ', $message); // Normalize whitespace
                    
                    // Format message nicely
                    if ($lineNum !== null) {
                        $message = sprintf('Line %d%s: %s', 
                            $lineNum, 
                            $columnNum !== null ? ", column {$columnNum}" : '',
                            $message
                        );
                    }

                    $issues[] = new ValidationIssue(
                        file: $fileName,
                        severity: $severity,
                        message: $message,
                        schedaId: null,
                        line: $lineNum,
                        column: $columnNum,
                    );

                    // Reset for next error
                    $currentMessage = '';
                    $lineNum = null;
                    $columnNum = null;
                    $severity = 'error';
                }
            }
        }

        // Handle last message if not terminated
        if (! empty($currentMessage)) {
            $message = trim($currentMessage);
            $message = preg_replace('/\s+/', ' ', $message);
            
            if ($lineNum !== null) {
                $message = sprintf('Line %d%s: %s', 
                    $lineNum, 
                    $columnNum !== null ? ", column {$columnNum}" : '',
                    $message
                );
            }

            $issues[] = new ValidationIssue(
                file: $fileName,
                severity: $severity,
                message: $message,
                schedaId: null,
                line: $lineNum,
                column: $columnNum,
            );
        }

        // If no issues parsed but output exists, create a generic issue
        if (empty($issues) && ! empty(trim($output))) {
            // Clean output for display
            $cleanOutput = trim($output);
            $cleanOutput = preg_replace('/\s+/', ' ', $cleanOutput);
            $cleanOutput = substr($cleanOutput, 0, 500);
            
            $issues[] = new ValidationIssue(
                file: $fileName,
                severity: 'error',
                message: "Validation failed: {$cleanOutput}",
            );
        }

        return $issues;
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
