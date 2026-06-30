<?php

declare(strict_types=1);

namespace App\Services\AddedFields;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Estrae uno zip per Add Fields: consente solo .xlsx, .xls e file immagine.
 * Restituisce percorsi Excel e nomi file immagine (basename) per il matching "Nome file immagine".
 */
class AddFieldsZipExtractor
{
    private const ALLOWED_EXCEL_EXT = ['xlsx', 'xls'];

    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'gif', 'webp', 'bmp'];

    private const MAX_FILES = 2000;

    private const MAX_TOTAL_BYTES = 512 * 1024 * 1024; // 512 MB

    /**
     * Estrae lo zip in extractionPath e restituisce excel paths e basename delle immagini.
     *
     * @param  string  $zipPath  Path al file .zip
     * @param  string  $extractionPath  Cartella di estrazione (deve esistere o essere creabile)
     * @return array{excel_paths: array<string>, image_basenames: array<string>, warnings: array<string>}
     *
     * @throws RuntimeException
     */
    public function extract(string $zipPath, string $extractionPath): array
    {
        if (! file_exists($zipPath) || ! is_readable($zipPath)) {
            throw new RuntimeException("ZIP file not found or not readable: {$zipPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Failed to open ZIP: {$zipPath}");
        }

        $allowedExt = array_merge(self::ALLOWED_EXCEL_EXT, self::ALLOWED_IMAGE_EXT);
        $basePath = realpath($extractionPath) ?: $extractionPath;
        $filesToExtract = [];
        $totalSize = 0;
        $warnings = [];
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || str_ends_with($entryName, '/')) {
                continue;
            }
            $count++;
            if ($count > self::MAX_FILES) {
                $warnings[] = 'Troppi file nello zip; estrazione limitata.';
                break;
            }
            $safePath = $this->safePath($entryName, $extractionPath);
            if ($safePath === null) {
                $warnings[] = "Path non sicuro ignorato: {$entryName}";
                continue;
            }
            $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
            if (! in_array($ext, $allowedExt, true)) {
                continue;
            }
            $fileInfo = $zip->statIndex($i);
            $size = $fileInfo['size'] ?? 0;
            $totalSize += $size;
            if ($totalSize > self::MAX_TOTAL_BYTES) {
                $warnings[] = 'Dimensione totale zip superata; estrazione interrotta.';
                break;
            }
            $filesToExtract[] = ['entry' => $entryName, 'target' => $safePath];
        }

        foreach ($filesToExtract as $item) {
            $dir = dirname($item['target']);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $content = $zip->getFromName($item['entry']);
            if ($content !== false && file_put_contents($item['target'], $content) === false) {
                $warnings[] = "Impossibile scrivere: {$item['target']}";
            }
        }
        $zip->close();

        $excelPaths = $this->globExtensions($extractionPath, self::ALLOWED_EXCEL_EXT);
        $imagePaths = $this->globExtensions($extractionPath, self::ALLOWED_IMAGE_EXT);
        $imageBasenames = array_values(array_unique(array_map('basename', $imagePaths)));

        Log::info('AddFieldsZipExtractor: extracted', [
            'extraction_path' => $extractionPath,
            'excel_count' => count($excelPaths),
            'image_count' => count($imageBasenames),
            'warnings' => $warnings,
        ]);

        return [
            'excel_paths' => $excelPaths,
            'image_basenames' => $imageBasenames,
            'warnings' => $warnings,
        ];
    }

    private function safePath(string $entryName, string $baseDir): ?string
    {
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        $name = str_replace('\\', '/', $entryName);
        $name = preg_replace('#/+#', '/', $name);
        if (str_starts_with($name, '/') || str_contains($name, '..')) {
            return null;
        }
        $resolved = $baseDir.'/'.$name;
        $realBase = realpath($baseDir);
        $realResolved = realpath(dirname($resolved));
        if ($realBase === false || $realResolved === false) {
            return $resolved;
        }
        return str_starts_with($realResolved, $realBase) ? $resolved : null;
    }

    /**
     * Cerca ricorsivamente i file con le estensioni date sotto $dir.
     * Non usa glob() perché ** non è supportato in PHP per la ricorsione (su Windows fallisce).
     *
     * @param  array<string>  $extensions
     * @return array<string>
     */
    private function globExtensions(string $dir, array $extensions): array
    {
        $out = [];
        if (! is_dir($dir)) {
            return $out;
        }
        $extSet = array_flip(array_map('strtolower', $extensions));
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (isset($extSet[$ext])) {
                    $out[] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AddFieldsZipExtractor: globExtensions failed', ['dir' => $dir, 'error' => $e->getMessage()]);
        }
        return $out;
    }
}
