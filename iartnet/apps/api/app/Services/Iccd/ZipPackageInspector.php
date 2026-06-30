<?php

declare(strict_types=1);

namespace App\Services\Iccd;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

class ZipPackageInspector
{
    /**
     * Allowed file extensions for extraction.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = [
        'xml',
        'json',
        'jpg',
        'jpeg',
        'png',
        'tif',
        'tiff',
        'pdf',
        'mp3',
        'mp4',
    ];

    /**
     * Maximum number of files to extract.
     */
    private const MAX_FILES = 5000;

    /**
     * Maximum total extracted size in bytes (2GB).
     */
    private const MAX_TOTAL_SIZE = 2 * 1024 * 1024 * 1024;

    /**
     * Inspect and extract ZIP package safely.
     *
     * @param  string  $zipPath  Path to the ZIP file
     * @param  string  $extractionPath  Base path for extraction
     * @return array{files: array<string>, xml_files: array<string>, json_files: array<string>, media_files: array<string>, warnings: array<string>, format: PackageFormat}
     *
     * @param  string|null  $dataProvider  Data provider della mirror (SIGEC, SIRBEC, ...). Se SIGEC, per ICCD è accettato il pacchetto senza IMMFTAN (MIDF).
     * @throws RuntimeException If extraction fails or package is invalid
     */
    public function inspectAndExtract(string $zipPath, string $extractionPath, ?string $dataProvider = null): array
    {
        if (! file_exists($zipPath)) {
            throw new RuntimeException("ZIP file not found: {$zipPath}");
        }

        if (! is_readable($zipPath)) {
            throw new RuntimeException("ZIP file is not readable: {$zipPath}");
        }

        // Create extraction directory if it doesn't exist
        if (! is_dir($extractionPath)) {
            if (! mkdir($extractionPath, 0755, true)) {
                throw new RuntimeException("Failed to create extraction directory: {$extractionPath}");
            }
        }

        if (! is_writable($extractionPath)) {
            throw new RuntimeException("Extraction path is not writable: {$extractionPath}");
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($result !== true) {
            throw new RuntimeException("Failed to open ZIP file: {$zipPath} (error code: {$result})");
        }

        $files = [];
        $xmlFiles = [];
        $jsonFiles = [];
        $mediaFiles = [];
        $warnings = [];
        $totalSize = 0;
        $fileCount = 0;

        // First pass: validate all entries
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName === false) {
                continue;
            }

            // Skip directory entries
            if (str_ends_with($entryName, '/')) {
                continue;
            }

            $fileCount++;

            if ($fileCount > self::MAX_FILES) {
                $warnings[] = "Package contains more than ".self::MAX_FILES." files. Only first ".self::MAX_FILES." will be processed.";
                break;
            }

            // Validate path (prevent zip slip)
            $safePath = $this->validateAndNormalizePath($entryName, $extractionPath);

            if ($safePath === null) {
                $warnings[] = "Skipping potentially unsafe path: {$entryName}";
                continue;
            }

            $fileInfo = $zip->statIndex($i);
            $fileSize = $fileInfo['size'] ?? 0;
            $totalSize += $fileSize;

            if ($totalSize > self::MAX_TOTAL_SIZE) {
                $warnings[] = "Package total size exceeds ".self::formatBytes(self::MAX_TOTAL_SIZE).". Extraction stopped.";
                break;
            }

            $extension = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));

            // Check allowed extensions
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $warnings[] = "File with disallowed extension skipped: {$entryName}";
                continue;
            }

            $files[] = $safePath;

            if ($extension === 'xml') {
                $xmlFiles[] = $safePath;
            } elseif ($extension === 'json') {
                $jsonFiles[] = $safePath;
            } else {
                $mediaFiles[] = $safePath;
            }
        }

        // Second pass: extract files
        $extractedCount = 0;
        for ($i = 0; $i < $zip->numFiles && $extractedCount < count($files); $i++) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName === false || str_ends_with($entryName, '/')) {
                continue;
            }

            $safePath = $this->validateAndNormalizePath($entryName, $extractionPath);

            if ($safePath === null || ! in_array($safePath, $files, true)) {
                continue;
            }

            // Create directory structure if needed
            $targetDir = dirname($safePath);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Extract file
            $content = $zip->getFromIndex($i);

            if ($content === false) {
                $warnings[] = "Failed to extract file: {$entryName}";
                continue;
            }

            if (file_put_contents($safePath, $content) === false) {
                $warnings[] = "Failed to write extracted file: {$safePath}";
                continue;
            }

            $extractedCount++;
        }

        $zip->close();

        // Check for required files (per ICCD; per SIGEC/MIDF IMMFTAN non è obbligatorio)
        $this->checkRequiredFiles($xmlFiles, $warnings, $dataProvider);

        // Detect package format (SIGEC permette MIDF senza IMMFTAN)
        $formatDetector = new PackageFormatDetector();
        $detectedFormat = $formatDetector->detectFormat($extractionPath, $xmlFiles, $files, $dataProvider);

        Log::info("ZIP package extracted", [
            'zip_path' => $zipPath,
            'extraction_path' => $extractionPath,
            'total_files' => count($files),
            'xml_files' => count($xmlFiles),
            'json_files' => count($jsonFiles),
            'media_files' => count($mediaFiles),
            'format' => $detectedFormat->value,
            'warnings_count' => count($warnings),
        ]);

        return [
            'files' => $files,
            'xml_files' => $xmlFiles,
            'json_files' => $jsonFiles,
            'media_files' => $mediaFiles,
            'warnings' => $warnings,
            'format' => $detectedFormat,
        ];
    }

    /**
     * Validate and normalize file path to prevent zip slip attacks.
     *
     * @param  string  $entryName  Entry name from ZIP
     * @param  string  $basePath  Base extraction path
     * @return string|null  Safe normalized path or null if unsafe
     */
    private function validateAndNormalizePath(string $entryName, string $basePath): ?string
    {
        // Normalize path separators
        $normalized = str_replace('\\', '/', $entryName);

        // Remove leading slashes and dots
        $normalized = ltrim($normalized, '/.');

        // Check for path traversal attempts
        if (str_contains($normalized, '..')) {
            return null;
        }

        // Check for absolute paths or drive letters (Windows)
        if (preg_match('/^[a-z]:/i', $normalized)) {
            return null;
        }

        // Check for null bytes (path injection)
        if (str_contains($normalized, "\0")) {
            return null;
        }

        // Get real path of base directory (must exist)
        $realBase = realpath($basePath);
        if ($realBase === false) {
            return null;
        }

        // Normalize the base path for comparison (use forward slashes)
        $realBaseNormalized = str_replace('\\', '/', $realBase);

        // Build safe path
        $safePath = rtrim($realBaseNormalized, '/').'/'.$normalized;
        $safePathNormalized = str_replace('\\', '/', $safePath);

        // Ensure the normalized path starts with the base path
        if (! str_starts_with($safePathNormalized, $realBaseNormalized.'/') && $safePathNormalized !== $realBaseNormalized) {
            return null;
        }

        // Additional validation: try to resolve parent directory if it exists
        // This helps catch symlink attacks, but doesn't fail if directory doesn't exist yet
        $parentDir = dirname($safePath);
        $realParent = realpath($parentDir);

        // If parent directory exists, verify it's within base
        if ($realParent !== false) {
            $realParentNormalized = str_replace('\\', '/', $realParent);
            if (! str_starts_with($realParentNormalized, $realBaseNormalized)) {
                return null;
            }
        }

        // Path is safe - return the safe path (using original basePath format for consistency)
        return rtrim($basePath, '/').'/'.$normalized;
    }

    /**
     * Check for required ICCD package files.
     * Per SIGEC (MIDF) IMMFTAN non è obbligatorio.
     *
     * @param  array<string>  $xmlFiles  List of extracted XML files
     * @param  array<string>  $warnings  Warnings array (by reference)
     * @param  string|null  $dataProvider  Se SIGEC, non si richiede IMMFTAN.XML
     * @return void
     */
    private function checkRequiredFiles(array $xmlFiles, array &$warnings, ?string $dataProvider = null): void
    {
        $fileNames = array_map('basename', $xmlFiles);
        $fileNamesLower = array_map('strtolower', $fileNames);

        $requiredFiles = ['INFORMA.XML'];
        if (strtoupper(trim($dataProvider ?? '')) !== 'SIGEC') {
            $requiredFiles[] = 'IMMFTAN.XML';
        }
        $missingFiles = [];

        foreach ($requiredFiles as $required) {
            if (! in_array(strtolower($required), $fileNamesLower, true)) {
                $missingFiles[] = $required;
            }
        }

        if (! empty($missingFiles)) {
            $warnings[] = 'Missing required files: '.implode(', ', $missingFiles).'. Package may not be fully compliant.';
        }

        // Check for ICCD data files (pattern: Tipo + CodiceEnte + TipoScheda.xml)
        $hasIccdDataFiles = false;
        foreach ($fileNames as $fileName) {
            if (preg_match('/^[SA]\w+[A-Z]{2}\.xml$/i', $fileName)) {
                $hasIccdDataFiles = true;
                break;
            }
        }

        if (! $hasIccdDataFiles) {
            $warnings[] = 'No ICCD data files found (expected INFORMA.XML, IMMFTAN.XML SAI652O*.xml).';
        }
    }

    /**
     * Format bytes to human-readable string.
     *
     * @param  int  $bytes  Bytes to format
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
