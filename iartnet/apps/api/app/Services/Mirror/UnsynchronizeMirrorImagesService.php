<?php

declare(strict_types=1);

namespace App\Services\Mirror;

use App\Models\MirrorRecordAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rimuove dal Master le immagini IIIF copiate in IMAGES_ROOT durante la sincronizzazione Mirror → Master,
 * usando la tabella asset dello schema Mirror (campo filename = URL IIIF).
 */
final class UnsynchronizeMirrorImagesService
{
    private const MASTER_SCHEMA = 'iartnet_master';

    /**
     * Estrae da un filename/URL IIIF (Image API 2.x) l'id web_resources (UUID) e il nome file in IMAGES_ROOT ({uuid}.{ext}).
     *
     * Formato atteso: .../iiif/2/{uuid}.{ext}/... (es. .../iiif/2/4140d7d3-....jpg/full/max/0/default.jpg).
     * Se filename non contiene un percorso IIIF con identificativo UUID.estensione, restituisce null.
     *
     * @return array{web_resource_id: string, storage_basename: string}|null
     */
    public function parseIiifUuidImageFromFilename(string $filename): ?array
    {
        $filename = trim($filename);
        if ($filename === '' || stripos($filename, '/iiif/2/') === false) {
            return null;
        }

        if (! preg_match('~/iiif/2/([^/#?]+)~i', $filename, $m)) {
            return null;
        }

        $segment = rawurldecode($m[1]);
        $basename = basename(str_replace('\\', '/', $segment));

        if (! preg_match(
            '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([a-zA-Z0-9]+)$/i',
            $basename,
            $um
        )) {
            return null;
        }

        $uuid = strtolower($um[1]);
        if (! Str::isUuid($uuid)) {
            return null;
        }

        $ext = strtolower($um[2]);

        return [
            'web_resource_id' => $uuid,
            'storage_basename' => $uuid.'.'.$ext,
        ];
    }

    /**
     * Per ogni riga asset dello schema Mirror: se filename è URL IIIF con UUID, elimina la riga web_resources
     * (scoped per institution) e il file sotto IMAGES_ROOT.
     *
     * @param  list<string>|null  $recordIdsFilter  Se valorizzato, limita l'operazione alle righe asset con record_id in elenco.
     *
     * @return array{
     *     success: bool,
     *     error: ?string,
     *     processed: int,
     *     success_count: int,
     *     error_count: int,
     *     skipped_count: int,
     *     error_details: list<array<string, mixed>>
     * }
     */
    public function execute(string $mirrorSchema, string $primaryInstitutionId, ?array $recordIdsFilter = null): array
    {
        $processed = 0;
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $errorDetails = [];

        $imagesRoot = rtrim((string) config('images.root', env('IMAGES_ROOT', '')), DIRECTORY_SEPARATOR);
        if ($imagesRoot === '' || ! is_dir($imagesRoot)) {
            return [
                'success' => false,
                'error' => 'IMAGES_ROOT non configurato o directory non valida. Verificare .env (IMAGES_ROOT).',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'error_details' => [],
            ];
        }

        try {
            $assetModel = MirrorRecordAsset::forSchema($mirrorSchema);
            $query = $assetModel->newQuery();
            if ($recordIdsFilter !== null && $recordIdsFilter !== []) {
                $query->whereIn('record_id', $recordIdsFilter);
            }
            $assets = $query->get(['id', 'filename']);

            foreach ($assets as $asset) {
                $processed++;
                $parsed = $this->parseIiifUuidImageFromFilename((string) $asset->filename);
                if ($parsed === null) {
                    $skippedCount++;
                    continue;
                }

                $filePath = $imagesRoot.DIRECTORY_SEPARATOR.$parsed['storage_basename'];
                $fileExistedBefore = is_file($filePath);

                try {
                    $rowDeleted = DB::delete(
                        'DELETE FROM '.self::MASTER_SCHEMA.'.web_resources w USING '
                        .self::MASTER_SCHEMA.'.records r '
                        .'WHERE w.id = ? AND w.record_id = r.id AND r.primary_institution_id = ?',
                        [$parsed['web_resource_id'], $primaryInstitutionId]
                    );
                } catch (Throwable $e) {
                    Log::error('UnsynchronizeMirrorImages: delete web_resources failed', [
                        'asset_id' => $asset->id,
                        'web_resource_id' => $parsed['web_resource_id'],
                        'error' => $e->getMessage(),
                    ]);
                    $errorDetails[] = [
                        'asset_id' => $asset->id,
                        'filename' => $asset->filename,
                        'error' => 'Errore DB durante eliminazione web_resources: '.$e->getMessage(),
                    ];
                    $errorCount++;
                    continue;
                }

                $fileRemovedOk = ! $fileExistedBefore || @unlink($filePath);

                if ($rowDeleted === 0) {
                    if ($fileExistedBefore && ! $fileRemovedOk) {
                        $errorDetails[] = [
                            'asset_id' => $asset->id,
                            'filename' => $asset->filename,
                            'error' => 'Nessuna riga web_resources eliminata per questo UUID e impossibile rimuovere il file da IMAGES_ROOT.',
                        ];
                        $errorCount++;
                    } else {
                        $successCount++;
                    }

                    continue;
                }

                if ($fileExistedBefore && ! $fileRemovedOk) {
                    $errorDetails[] = [
                        'asset_id' => $asset->id,
                        'filename' => $asset->filename,
                        'error' => 'Riga web_resources eliminata ma impossibile eliminare il file da IMAGES_ROOT: '.$filePath,
                    ];
                    $errorCount++;
                    continue;
                }

                $successCount++;
            }
        } catch (Throwable $e) {
            Log::error('UnsynchronizeMirrorImages: fatal', [
                'mirror_schema' => $mirrorSchema,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => $processed,
                'success_count' => $successCount,
                'error_count' => $errorCount + 1,
                'skipped_count' => $skippedCount,
                'error_details' => $errorDetails,
            ];
        }

        return [
            'success' => $errorCount === 0,
            'error' => null,
            'processed' => $processed,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'skipped_count' => $skippedCount,
            'error_details' => $errorDetails,
        ];
    }
}
