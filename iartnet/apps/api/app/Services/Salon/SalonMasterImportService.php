<?php

declare(strict_types=1);

namespace App\Services\Salon;

use App\Services\Iiif\IiifImageService;
use App\Support\IngestionPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Crea record Master SALON + record_kv + i18n_texts + web_resources da materiali sotto INGEST_FS_ROOT/salons/{runId}.
 *
 * Le immagini in ingestion/images vengono rimosse solo dopo commit riuscito di tutte le copie in IMAGES_ROOT
 * (stesso file può essere referenziato da più pagine Pg_*_img).
 */
final class SalonMasterImportService
{
    private const MASTER_SCHEMA = 'iartnet_master';

    /**
     * @return array{record_id: string, stable_id: string}
     */
    public function importFromPreparedRun(
        string $stableId,
        string $institutionId,
        string $ingestRunId
    ): array {
        $stableId = trim($stableId);
        if ($stableId === '') {
            throw new RuntimeException('Il codice scheda SALON (stable_id) è obbligatorio.');
        }

        $exists = DB::connection('pgsql')->table(self::MASTER_SCHEMA.'.records')
            ->where('stable_id', $stableId)
            ->exists();
        if ($exists) {
            throw new RuntimeException("Esiste già una scheda con codice (stable_id) '{$stableId}'.");
        }

        $runDir = IngestionPaths::salonImportRunRoot($ingestRunId);
        if (! is_dir($runDir)) {
            throw new RuntimeException('Cartella import Salon non trovata. Ripetere lo step di upload.');
        }

        $excelPath = $runDir.DIRECTORY_SEPARATOR.'data.xlsx';
        if (! is_file($excelPath)) {
            throw new RuntimeException('File Excel (data.xlsx) mancante nella cartella di import.');
        }

        $imagesDir = $runDir.DIRECTORY_SEPARATOR.'images';
        if (! is_dir($imagesDir)) {
            throw new RuntimeException('Cartella immagini estratte mancante. Ripetere lo step di upload.');
        }

        $parsed = (new SalonExcelParser)->parse($excelPath);
        $imagePathByBasename = $this->indexImagesByBasename($imagesDir);

        $this->validatePageImages($parsed['field_pairs'], $imagePathByBasename);

        $iiifPublicBase = rtrim((string) config('services.iiif.public_base', env('IIIF_PUBLIC_BASE', '')), '/');
        if ($iiifPublicBase === '') {
            throw new RuntimeException('IIIF_PUBLIC_BASE non configurato: necessario per registrare le immagini.');
        }

        $imagesRoot = rtrim((string) config('images.root', env('IMAGES_ROOT', '')), DIRECTORY_SEPARATOR);
        if ($imagesRoot === '' || ! is_dir($imagesRoot) || ! is_writable($imagesRoot)) {
            throw new RuntimeException('IMAGES_ROOT non configurato o non scrivibile.');
        }

        try {
            $iiifService = new IiifImageService;
        } catch (\Throwable $e) {
            throw new RuntimeException('Servizio IIIF non disponibile: '.$e->getMessage(), 0, $e);
        }

        /** @var array<string, true> path assoluti in ingestion da rimuovere dopo import riuscito */
        $ingestionPathsToCleanup = [];

        $result = DB::connection('pgsql')->transaction(function () use (
            $stableId,
            $institutionId,
            $parsed,
            $imagePathByBasename,
            $ingestRunId,
            $iiifService,
            $iiifPublicBase,
            $imagesRoot,
            &$ingestionPathsToCleanup
        ): array {
            $recordId = (string) Str::uuid();
            $now = now();

            DB::table(self::MASTER_SCHEMA.'.records')->insert([
                'id' => $recordId,
                'stable_id' => $stableId,
                'primary_institution_id' => $institutionId,
                'edm_type' => 'TEXT',
                'publish_state' => 'draft',
                'primary_lang' => 'it',
                'is_translated' => true,
                'ext_json' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $kvIdsByKey = $this->insertRecordKvAndI18n($recordId, $parsed['field_pairs']);

            $ord = 0;
            foreach ($parsed['field_pairs'] as $pair) {
                $key = $pair['key'];
                if (! preg_match('/^Pg_(\d+)_img$/', $key, $m)) {
                    continue;
                }
                $filename = trim((string) ($pair['value'] ?? ''));
                if ($filename === '') {
                    continue;
                }
                $basename = basename(str_replace('\\', '/', $filename));
                $sourcePath = $imagePathByBasename[$basename]
                    ?? $imagePathByBasename[strtolower($basename)]
                    ?? null;
                if ($sourcePath === null) {
                    throw new RuntimeException("Immagine non trovata nello zip: {$basename}");
                }

                $ingestionPathsToCleanup[$sourcePath] = true;

                $ord++;
                $iiifUrl = $this->importOneImage(
                    $recordId,
                    $sourcePath,
                    $imagesRoot,
                    $iiifPublicBase,
                    $iiifService,
                    $ingestRunId,
                    $ord
                );

                $kvId = $kvIdsByKey[$key] ?? null;
                if ($kvId !== null) {
                    DB::table(self::MASTER_SCHEMA.'.record_kv')
                        ->where('id', $kvId)
                        ->update([
                            'value_text' => $iiifUrl,
                            'updated_at' => now(),
                        ]);
                }
                $this->updateI18nEn($recordId, $key, $iiifUrl);
            }

            return [
                'record_id' => $recordId,
                'stable_id' => $stableId,
            ];
        });

        foreach (array_keys($ingestionPathsToCleanup) as $path) {
            $this->deleteSourceIfUnderIngestion($path);
        }

        return $result;
    }

    /**
     * @param  list<array{key: string, value: string}>  $fieldPairs
     * @param  array<string, string>  $imagePathByBasename
     */
    private function validatePageImages(array $fieldPairs, array $imagePathByBasename): void
    {
        foreach ($fieldPairs as $pair) {
            if (! preg_match('/^Pg_\d+_img$/', $pair['key'])) {
                continue;
            }
            $filename = trim((string) ($pair['value'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $basename = basename(str_replace('\\', '/', $filename));
            if (! isset($imagePathByBasename[$basename]) && ! isset($imagePathByBasename[strtolower($basename)])) {
                throw new RuntimeException(
                    "Nel ".SalonExcelParser::SHEET2_NAME." è indicata l'immagine '{$basename}' ma il file non è presente nello zip."
                );
            }
        }
    }

    /**
     * @param  list<array{key: string, value: string}>  $fieldPairs
     * @return array<string, string> key => record_kv id
     */
    private function insertRecordKvAndI18n(string $recordId, array $fieldPairs): array
    {
        $meta = [
            'source_standard' => 'SALON_IMPORT',
            'source_field' => 'salon_excel',
            'import_process' => 'salon_to_master',
        ];
        $extJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $kvIdsByKey = [];

        foreach ($fieldPairs as $row) {
            $key = $row['key'];
            $valueText = (string) ($row['value'] ?? '');
            $datatype = $key === 'card_type' ? 'string' : 'string';
            $kvId = (string) Str::uuid();

            DB::table(self::MASTER_SCHEMA.'.record_kv')->insert([
                'id' => $kvId,
                'record_id' => $recordId,
                'key' => $key,
                'datatype' => $datatype,
                'value_text' => $valueText,
                'value_uri' => null,
                'value_json' => null,
                'display_order' => 0,
                'origin' => 'authoritative',
                'ext_json' => $extJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $kvIdsByKey[$key] = $kvId;
            $this->insertI18nEn($recordId, $key, $valueText);
        }

        return $kvIdsByKey;
    }

    private function insertI18nEn(string $recordId, string $fieldName, string $textValue): void
    {
        if ($textValue === '') {
            return;
        }

        DB::table(self::MASTER_SCHEMA.'.i18n_texts')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'record',
            'entity_id' => $recordId,
            'field_name' => $fieldName,
            'lang' => 'en',
            'text_value' => $textValue,
            'origin' => 'authoritative',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function updateI18nEn(string $recordId, string $fieldName, string $textValue): void
    {
        $updated = DB::table(self::MASTER_SCHEMA.'.i18n_texts')
            ->where('entity_type', 'record')
            ->where('entity_id', $recordId)
            ->where('field_name', $fieldName)
            ->where('lang', 'en')
            ->update([
                'text_value' => $textValue,
                'updated_at' => now(),
            ]);

        if ($updated === 0 && $textValue !== '') {
            $this->insertI18nEn($recordId, $fieldName, $textValue);
        }
    }

    /**
     * @return array<string, string> basename (case-insensitive key) => absolute path
     */
    private function indexImagesByBasename(string $imagesDir): array
    {
        $map = [];
        foreach (glob($imagesDir.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $base = basename($path);
            $map[$base] = $path;
            $map[strtolower($base)] = $path;
        }

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($imagesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    continue;
                }
                $base = $file->getFilename();
                $path = $file->getPathname();
                $map[$base] = $path;
                $map[strtolower($base)] = $path;
            }
        } catch (\Throwable) {
            // ignore
        }

        return $map;
    }

    private function importOneImage(
        string $recordId,
        string $sourcePath,
        string $imagesRoot,
        string $iiifPublicBase,
        IiifImageService $iiifService,
        string $ingestRunId,
        int $ord
    ): string {
        $webResourceId = (string) Str::uuid();
        $checksum = $iiifService->calculateSha256($sourcePath);

        $existing = DB::table(self::MASTER_SCHEMA.'.web_resources')
            ->where('record_id', $recordId)
            ->where('checksum_sha256', $checksum)
            ->first();
        if ($existing !== null) {
            return (string) $existing->url;
        }

        $iiifIdentifier = $this->copyImageToImagesRootUuid($sourcePath, $imagesRoot, $webResourceId);
        $baseUrl = $iiifPublicBase.'/'.$iiifIdentifier;
        $mimeType = $iiifService->getMimeType($sourcePath);
        $dimensions = $iiifService->getImageDimensions($sourcePath);
        $iiifUrl = $iiifService->buildIiifUrl($baseUrl);

        DB::table(self::MASTER_SCHEMA.'.web_resources')->insert([
            'id' => $webResourceId,
            'record_id' => $recordId,
            'role' => 'iiif_image_api',
            'url' => $iiifUrl,
            'mime_type' => $mimeType,
            'checksum_sha256' => $checksum,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'iiif_image_api_url' => $baseUrl,
            'ord' => $ord,
            'ext_json' => json_encode([
                'source' => [
                    'standard' => 'SALON_IMPORT',
                    'ingest_run_id' => $ingestRunId,
                    'filename' => basename($sourcePath),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $iiifUrl;
    }

    private function copyImageToImagesRootUuid(string $sourcePath, string $imagesRoot, string $targetBasename): string
    {
        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }
        $destFileName = $targetBasename.'.'.$ext;
        $destPath = $imagesRoot.DIRECTORY_SEPARATOR.$destFileName;
        if (@copy($sourcePath, $destPath) === false) {
            throw new RuntimeException("Copia immagine fallita verso IMAGES_ROOT: {$destPath}");
        }

        return $destFileName;
    }

    private function deleteSourceIfUnderIngestion(string $imagePath): void
    {
        $ingestionRoot = rtrim((string) config('ingestion.fs_root'), DIRECTORY_SEPARATOR);
        if ($ingestionRoot === '') {
            return;
        }
        $realRoot = realpath($ingestionRoot);
        $realPath = $imagePath !== '' ? realpath($imagePath) : false;
        if ($realRoot === false || $realPath === false || ! is_file($imagePath)) {
            return;
        }
        $prefix = $realRoot.DIRECTORY_SEPARATOR;
        if (! str_starts_with($realPath, $prefix) && $realPath !== $realRoot) {
            return;
        }
        @unlink($imagePath);
    }
}
