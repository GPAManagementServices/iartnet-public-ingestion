<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Centralized path builder for ingestion workflow (unzip, extraction, tmp, run storage).
 * All paths derive from config('ingestion.fs_root') (INGEST_FS_ROOT).
 * Does not create directories; caller is responsible for mkdir.
 */
class IngestionPaths
{
    private static ?string $root = null;

    public static function root(): string
    {
        if (self::$root === null) {
            self::$root = rtrim((string) config('ingestion.fs_root'), DIRECTORY_SEPARATOR);
        }

        return self::$root;
    }

    /**
     * Base directory for a run (extraction content lives here).
     */
    public static function runRoot(string $runId): string
    {
        return self::root().DIRECTORY_SEPARATOR.$runId;
    }

    /**
     * Extraction path for a run (where ZIP is extracted). Same as runRoot.
     */
    public static function extractionPath(string $runId): string
    {
        return self::runRoot($runId);
    }

    /**
     * Temporary path for a run (e.g. copied XML, media).
     */
    public static function tmpPath(string $runId): string
    {
        return self::root().DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.$runId;
    }

    /**
     * Run metadata storage path (package.json, validation.json, import.json, logs).
     */
    public static function runStoragePath(string $runId): string
    {
        return self::root().DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR.$runId;
    }

    /**
     * Cartella dedicata all'import interviste (upload .docx / .jpg prima della creazione scheda Master).
     */
    public static function interviewImportRunRoot(string $runId): string
    {
        return self::root().DIRECTORY_SEPARATOR.'interviews'.DIRECTORY_SEPARATOR.$runId;
    }

    /**
     * Cartella dedicata all'import Salon (Excel + zip immagini prima della creazione scheda Master).
     */
    public static function salonImportRunRoot(string $runId): string
    {
        return self::root().DIRECTORY_SEPARATOR.'salons'.DIRECTORY_SEPARATOR.$runId;
    }

    /**
     * Verifica che un path sia sotto la root di ingestion (sicurezza).
     */
    private static function pathIsUnderRoot(string $path): bool
    {
        $root = self::root();
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false) {
            return false;
        }

        return str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR) || $realPath === $realRoot;
    }

    /**
     * Elimina la cartella di estrazione (root/runId) e tutti i file in essa.
     * Usato prima di eliminare un mirror instance (pulizia cartelle legate a import_run).
     */
    public static function deleteRunExtractionPath(string $runId): void
    {
        $extractionPath = self::extractionPath($runId);
        if (self::pathIsUnderRoot($extractionPath) && is_dir($extractionPath)) {
            File::deleteDirectory($extractionPath);
            Log::info('Ingestion cleanup: deleted run extraction path', ['run_id' => $runId, 'path' => $extractionPath]);
        }
    }

    /**
     * Elimina la cartella di estrazione (root/runId) e la cartella tmp (root/tmp/runId) per un run.
     * Usato quando l'utente non prosegue dopo lo step 2 (timeout pulizia).
     */
    public static function deleteExtractionAndTmp(string $runId): void
    {
        $extractionPath = self::extractionPath($runId);
        $tmpPath = self::tmpPath($runId);
        if (self::pathIsUnderRoot($extractionPath) && is_dir($extractionPath)) {
            File::deleteDirectory($extractionPath);
            Log::info('Ingestion cleanup: deleted extraction path', ['run_id' => $runId, 'path' => $extractionPath]);
        }
        if (self::pathIsUnderRoot($tmpPath) && is_dir($tmpPath)) {
            File::deleteDirectory($tmpPath);
            Log::info('Ingestion cleanup: deleted tmp path', ['run_id' => $runId, 'path' => $tmpPath]);
        }
    }

    /**
     * Elimina solo la cartella tmp (root/tmp/runId) per un run.
     * Usato al termine dell'import completato (la cartella in root resta).
     */
    public static function deleteTmpOnly(string $runId): void
    {
        $tmpPath = self::tmpPath($runId);
        if (self::pathIsUnderRoot($tmpPath) && is_dir($tmpPath)) {
            File::deleteDirectory($tmpPath);
            Log::info('Ingestion cleanup: deleted tmp path after import', ['run_id' => $runId, 'path' => $tmpPath]);
        }
    }
}
