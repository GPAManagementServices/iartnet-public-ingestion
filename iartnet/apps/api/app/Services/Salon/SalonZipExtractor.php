<?php

declare(strict_types=1);

namespace App\Services\Salon;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Estrae uno zip Salon: solo immagini .jpg / .jpeg / .png nella cartella di destinazione.
 */
final class SalonZipExtractor
{
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png'];

    private const MAX_FILES = 2000;

    private const MAX_TOTAL_BYTES = 512 * 1024 * 1024;

    /**
     * @return array{image_paths: list<string>, warnings: list<string>}
     */
    public function extract(string $zipPath, string $extractionPath): array
    {
        if (! is_file($zipPath) || ! is_readable($zipPath)) {
            throw new RuntimeException("File ZIP non trovato o non leggibile: {$zipPath}");
        }

        if (! is_dir($extractionPath) && ! mkdir($extractionPath, 0755, true) && ! is_dir($extractionPath)) {
            throw new RuntimeException("Impossibile creare la cartella di estrazione: {$extractionPath}");
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Impossibile aprire lo ZIP: {$zipPath}");
        }

        $filesToExtract = [];
        $warnings = [];
        $totalSize = 0;
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
            $ext = strtolower((string) pathinfo($entryName, PATHINFO_EXTENSION));
            if (! in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
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

        $imagePaths = $this->collectImages($extractionPath);

        Log::info('SalonZipExtractor: extracted', [
            'extraction_path' => $extractionPath,
            'image_count' => count($imagePaths),
            'warnings' => $warnings,
        ]);

        return [
            'image_paths' => $imagePaths,
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
     * @return list<string>
     */
    private function collectImages(string $dir): array
    {
        $out = [];
        if (! is_dir($dir)) {
            return $out;
        }
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
                    $out[] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SalonZipExtractor: collectImages failed', ['dir' => $dir, 'error' => $e->getMessage()]);
        }
        sort($out, SORT_STRING);

        return array_values($out);
    }
}
