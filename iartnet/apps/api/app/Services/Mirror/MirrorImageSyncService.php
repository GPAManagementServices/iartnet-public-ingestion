<?php

declare(strict_types=1);

namespace App\Services\Mirror;

use App\Enums\MirrorImageSyncMode;
use App\Models\MirrorRecord;
use App\Models\MirrorRecordAsset;
use App\Services\Iiif\IiifImageService;
use App\Services\Iiif\IiifVipsTiffPrepareService;
use App\Support\IngestionPaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sincronizza immagini Mirror → Master: copia diretta o preparazione IIIF via vips.
 */
final class MirrorImageSyncService
{
    private const MASTER_SCHEMA = 'iartnet_master';

    public function __construct(
        private readonly IiifImageService $iiifService = new IiifImageService,
        private readonly IiifVipsTiffPrepareService $vipsService = new IiifVipsTiffPrepareService,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     mode: string,
     *     processed: int,
     *     success_count: int,
     *     error_count: int,
     *     skipped_count: int,
     *     error_details: list<array<string, mixed>>
     * }
     */
    public function execute(
        string $mirrorSchema,
        string $institutionId,
        MirrorImageSyncMode $mode
    ): array {
        $lockKey = "mirror_image_sync:{$mirrorSchema}:{$institutionId}";
        $lock = Cache::lock($lockKey, (int) config('images.vips_job_timeout', 3600));

        if (! $lock->get()) {
            return $this->emptyResult(
                false,
                'Sincronizzazione immagini già in corso per questa Mirror Instance.',
                $mode
            );
        }

        $stats = [
            'processed' => 0,
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];
        $errorDetails = [];

        try {
            if ($mode === MirrorImageSyncMode::Vips) {
                $this->vipsService->assertAvailable();
            }

            $imagesRoot = rtrim((string) config('images.root'), DIRECTORY_SEPARATOR);
            if ($imagesRoot === '' || ! is_dir($imagesRoot) || ! is_writable($imagesRoot)) {
                throw new RuntimeException(
                    'IMAGES_ROOT non configurato o directory non scrivibile. Verificare .env (IMAGES_ROOT).'
                );
            }

            $iiifPublicBase = rtrim((string) config('services.iiif.public_base', ''), '/');
            if ($iiifPublicBase === '') {
                throw new RuntimeException('IIIF_PUBLIC_BASE non configurato nel file .env');
            }

            $assetModel = MirrorRecordAsset::forSchema($mirrorSchema);
            $assets = $assetModel->newQuery()
                ->where('promoted', false)
                ->where('exists_flag', true)
                ->get();

            Log::info('MirrorImageSyncService: starting', [
                'mirror_schema' => $mirrorSchema,
                'institution_id' => $institutionId,
                'mode' => $mode->value,
                'total_assets' => $assets->count(),
            ]);

            foreach ($assets as $asset) {
                $stats['processed']++;

                try {
                    $this->processAsset(
                        $asset,
                        $mirrorSchema,
                        $institutionId,
                        $mode,
                        $imagesRoot,
                        $iiifPublicBase,
                        $stats,
                        $errorDetails
                    );
                } catch (Throwable $e) {
                    $errorMessage = 'Errore generico durante la sincronizzazione: '.$e->getMessage();
                    Log::error('MirrorImageSyncService: asset error', [
                        'asset_id' => $asset->id,
                        'record_id' => $asset->record_id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    $errorDetails[] = [
                        'asset_id' => $asset->id ?? null,
                        'filename' => $asset->filename ?? 'N/A',
                        'record_id' => $asset->record_id ?? null,
                        'error' => $errorMessage,
                    ];
                    $stats['errors']++;
                }
            }

            Log::info('MirrorImageSyncService: completed', [
                'mirror_schema' => $mirrorSchema,
                'mode' => $mode->value,
                'stats' => $stats,
            ]);

            return [
                'success' => $stats['errors'] === 0,
                'error' => null,
                'mode' => $mode->value,
                'processed' => $stats['processed'],
                'success_count' => $stats['success'],
                'error_count' => $stats['errors'],
                'skipped_count' => $stats['skipped'],
                'error_details' => $errorDetails,
            ];
        } catch (Throwable $e) {
            Log::error('MirrorImageSyncService: fatal', [
                'mirror_schema' => $mirrorSchema,
                'institution_id' => $institutionId,
                'mode' => $mode->value,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'mode' => $mode->value,
                'processed' => $stats['processed'],
                'success_count' => $stats['success'],
                'error_count' => max($stats['errors'], 1),
                'skipped_count' => $stats['skipped'],
                'error_details' => $errorDetails,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{processed: int, success: int, errors: int, skipped: int}  $stats
     * @param  list<array<string, mixed>>  $errorDetails
     */
    private function processAsset(
        MirrorRecordAsset $asset,
        string $mirrorSchema,
        string $institutionId,
        MirrorImageSyncMode $mode,
        string $imagesRoot,
        string $iiifPublicBase,
        array &$stats,
        array &$errorDetails
    ): void {
        $masterRecord = DB::table(self::MASTER_SCHEMA.'.records')
            ->where('stable_id', $asset->record_id)
            ->where('primary_institution_id', $institutionId)
            ->first(['id']);

        if (! $masterRecord) {
            $errorDetails[] = $this->assetError($asset, "Record Master non trovato per record_id: {$asset->record_id}");
            $stats['skipped']++;

            return;
        }

        $mirrorRecord = MirrorRecord::forSchema($mirrorSchema)
            ->where('record_id', $asset->record_id)
            ->first(['import_run_id']);

        if (! $mirrorRecord || ! $mirrorRecord->import_run_id) {
            $errorDetails[] = $this->assetError($asset, "import_run_id non trovato per record_id: {$asset->record_id}");
            $stats['skipped']++;

            return;
        }

        $extractionPath = IngestionPaths::extractionPath($mirrorRecord->import_run_id);
        $imagePath = $this->resolveImagePathInExtraction($extractionPath, (string) $asset->filename);

        if ($imagePath === null) {
            $errorDetails[] = $this->assetError(
                $asset,
                "Immagine non trovata (cercata in root e in immagini): {$asset->filename}",
                ['image_path' => $extractionPath]
            );
            $stats['skipped']++;

            return;
        }

        if ($mode === MirrorImageSyncMode::Vips && ! $this->vipsService->isExtensionSupported($imagePath)) {
            $ext = pathinfo($imagePath, PATHINFO_EXTENSION);
            $errorDetails[] = $this->assetError(
                $asset,
                "Formato .{$ext} non supportato in modalità vips. Usare copia diretta o convertire il file."
            );
            $stats['errors']++;

            return;
        }

        $checksum = $this->iiifService->calculateSha256($imagePath);

        $existing = DB::table(self::MASTER_SCHEMA.'.web_resources')
            ->where('record_id', $masterRecord->id)
            ->where('checksum_sha256', $checksum)
            ->first();

        if ($existing) {
            $this->markAssetAsPromoted($mirrorSchema, (int) $asset->id, (string) $existing->url);
            $stats['success']++;

            return;
        }

        $webResourceId = (string) Str::uuid();
        $metadataPath = $imagePath;
        $derivativeChecksum = null;
        $sourceExt = strtolower((string) pathinfo($imagePath, PATHINFO_EXTENSION));

        try {
            if ($mode === MirrorImageSyncMode::Vips) {
                $iiifIdentifier = $this->prepareVipsOutput($imagePath, $imagesRoot, $webResourceId);
                $metadataPath = $imagesRoot.DIRECTORY_SEPARATOR.$iiifIdentifier;
                $derivativeChecksum = $this->iiifService->calculateSha256($metadataPath);
            } else {
                $iiifIdentifier = $this->copyToImagesRoot($imagePath, $imagesRoot, $webResourceId);
            }
        } catch (Throwable $e) {
            $errorDetails[] = $this->assetError(
                $asset,
                'Errore durante la scrittura in IMAGES_ROOT: '.$e->getMessage(),
                ['image_path' => $imagePath]
            );
            $stats['errors']++;

            return;
        }

        $baseUrl = $iiifPublicBase.'/'.$iiifIdentifier;
        $mimeType = $mode === MirrorImageSyncMode::Vips
            ? 'image/tiff'
            : $this->iiifService->getMimeType($imagePath);
        $dimensions = $mode === MirrorImageSyncMode::Vips
            ? $this->vipsService->readDimensions($metadataPath)
            : $this->iiifService->getImageDimensions($imagePath);
        $iiifUrl = $this->iiifService->buildIiifUrl($baseUrl);

        $extJson = [
            'source' => [
                'standard' => 'ICCD',
                'schema' => $mirrorSchema,
                'asset_id' => $asset->id,
                'filename' => $asset->filename,
                'import_run_id' => $mirrorRecord->import_run_id,
            ],
            'sync_mode' => $mode->value,
        ];

        if ($mode === MirrorImageSyncMode::Vips) {
            $extJson['iiif_prepare'] = [
                'engine' => 'vips',
                'source_ext' => $sourceExt,
                'output_ext' => 'tif',
                'source_checksum_sha256' => $checksum,
                'derivative_checksum_sha256' => $derivativeChecksum,
                'pyramid_mode' => (string) config('images.pyramid_mode', 'auto'),
            ];
        }

        DB::beginTransaction();

        try {
            DB::table(self::MASTER_SCHEMA.'.web_resources')->insert([
                'id' => $webResourceId,
                'record_id' => $masterRecord->id,
                'role' => 'iiif_image_api',
                'url' => $iiifUrl,
                'mime_type' => $mimeType,
                'checksum_sha256' => $checksum,
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'iiif_image_api_url' => $baseUrl,
                'ord' => $asset->id,
                'ext_json' => json_encode($extJson, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->markAssetAsPromoted($mirrorSchema, (int) $asset->id, $iiifUrl);
            $this->deleteSourceImageFromIngestion($imagePath);

            DB::commit();
            $stats['success']++;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->removeImagesRootFile($imagesRoot, $iiifIdentifier);
            $errorDetails[] = $this->assetError(
                $asset,
                "Errore durante l'inserimento in web_resources: {$e->getMessage()}"
            );
            $stats['errors']++;
        }
    }

    private function prepareVipsOutput(string $sourcePath, string $imagesRoot, string $webResourceId): string
    {
        $destFileName = $webResourceId.'.tif';
        $destPath = $imagesRoot.DIRECTORY_SEPARATOR.$destFileName;
        $tempPath = $imagesRoot.DIRECTORY_SEPARATOR.$webResourceId.'.tmp.tif';

        if (is_file($tempPath)) {
            @unlink($tempPath);
        }

        $this->vipsService->prepare($sourcePath, $tempPath);

        if (is_file($destPath)) {
            @unlink($destPath);
        }

        if (! @rename($tempPath, $destPath)) {
            @unlink($tempPath);
            throw new RuntimeException("Impossibile rinominare il file TIFF preparato: {$destPath}");
        }

        return $destFileName;
    }

    private function copyToImagesRoot(string $sourcePath, string $imagesRoot, string $webResourceId): string
    {
        $ext = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'jpg';
        }

        $destFileName = $webResourceId.'.'.$ext;
        $destPath = $imagesRoot.DIRECTORY_SEPARATOR.$destFileName;

        if (@copy($sourcePath, $destPath) === false) {
            throw new RuntimeException("Copia fallita da {$sourcePath} a {$destPath}");
        }

        return $destFileName;
    }

    private function removeImagesRootFile(string $imagesRoot, string $basename): void
    {
        $path = $imagesRoot.DIRECTORY_SEPARATOR.$basename;
        if (is_file($path)) {
            @unlink($path);
        }

        if (preg_match('/^(.+)\.(tiff?)$/i', $basename, $m)) {
            $vipsTempPath = $imagesRoot.DIRECTORY_SEPARATOR.$m[1].'.tmp.'.$m[2];
            if (is_file($vipsTempPath)) {
                @unlink($vipsTempPath);
            }
        }
    }

    private function markAssetAsPromoted(string $mirrorSchema, int $assetId, string $iiifUrl): void
    {
        MirrorRecordAsset::forSchema($mirrorSchema)
            ->where('id', $assetId)
            ->update([
                'promoted' => true,
                'filename' => $iiifUrl,
            ]);
    }

    private function deleteSourceImageFromIngestion(string $imagePath): void
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

    private function resolveImagePathInExtraction(string $extractionPath, string $filename): ?string
    {
        $sep = DIRECTORY_SEPARATOR;
        $candidates = [
            $extractionPath.$sep.$filename,
            $extractionPath.$sep.'immagini'.$sep.$filename,
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function assetError(MirrorRecordAsset $asset, string $message, array $extra = []): array
    {
        return array_merge([
            'asset_id' => $asset->id,
            'filename' => $asset->filename,
            'record_id' => $asset->record_id,
            'error' => $message,
        ], $extra);
    }

    /**
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     mode: string,
     *     processed: int,
     *     success_count: int,
     *     error_count: int,
     *     skipped_count: int,
     *     error_details: list<array<string, mixed>>
     * }
     */
    private function emptyResult(bool $success, ?string $error, MirrorImageSyncMode $mode): array
    {
        return [
            'success' => $success,
            'error' => $error,
            'mode' => $mode->value,
            'processed' => 0,
            'success_count' => 0,
            'error_count' => $error ? 1 : 0,
            'skipped_count' => 0,
            'error_details' => [],
        ];
    }
}
