<?php

declare(strict_types=1);

namespace App\Services\Interview;

use App\Models\Interview;
use App\Services\Mirror\UnsynchronizeMirrorImagesService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * Elimina un'intervista e tutti i dati Master collegati (record, KV, i18n, immagini IIIF).
 */
final class InterviewMasterDeleteService
{
    private const MASTER_SCHEMA = 'iartnet_master';

    public function __construct(
        private readonly UnsynchronizeMirrorImagesService $iiifParser,
    ) {}

    public function deleteInterview(Interview $interview): void
    {
        $interviewId = (string) $interview->id;
        $recordId = trim((string) ($interview->record_id ?? ''));

        /** @var Collection<int, stdClass> $webResources */
        $webResources = $recordId !== ''
            ? DB::connection('pgsql')
                ->table(self::MASTER_SCHEMA.'.web_resources')
                ->where('record_id', $recordId)
                ->get(['id', 'iiif_image_api_url', 'url'])
            : collect();

        DB::connection('pgsql')->transaction(function () use ($interviewId, $recordId, $webResources): void {
            $deleted = DB::table(self::MASTER_SCHEMA.'.interviews')
                ->where('id', $interviewId)
                ->delete();
            if ($deleted === 0) {
                throw new RuntimeException("Interview non trovata: {$interviewId}");
            }

            if ($recordId === '') {
                return;
            }

            $this->deleteImageFilesFromImagesRoot($webResources);

            DB::table(self::MASTER_SCHEMA.'.web_resources')
                ->where('record_id', $recordId)
                ->delete();

            DB::table(self::MASTER_SCHEMA.'.i18n_texts')
                ->where('entity_type', 'record')
                ->where('entity_id', $recordId)
                ->delete();

            DB::table(self::MASTER_SCHEMA.'.record_kv')
                ->where('record_id', $recordId)
                ->delete();

            DB::table(self::MASTER_SCHEMA.'.records')
                ->where('id', $recordId)
                ->delete();
        });
    }

    /**
     * @param  Collection<int, stdClass>  $webResources
     */
    private function deleteImageFilesFromImagesRoot(Collection $webResources): void
    {
        if ($webResources->isEmpty()) {
            return;
        }

        $imagesRoot = rtrim((string) config('images.root', env('IMAGES_ROOT', '')), DIRECTORY_SEPARATOR);
        if ($imagesRoot === '' || ! is_dir($imagesRoot)) {
            Log::warning('InterviewMasterDelete: IMAGES_ROOT non configurato o non valido; file immagine non rimossi', [
                'images_root' => $imagesRoot,
                'web_resource_count' => $webResources->count(),
            ]);

            return;
        }

        foreach ($webResources as $row) {
            $this->deleteOneImageFile(
                $imagesRoot,
                (string) $row->id,
                (string) ($row->iiif_image_api_url ?? ''),
                (string) ($row->url ?? '')
            );
        }
    }

    private function deleteOneImageFile(
        string $imagesRoot,
        string $webResourceId,
        string $iiifImageApiUrl,
        string $url
    ): void {
        $pathsToTry = [];

        foreach ([$iiifImageApiUrl, $url] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $parsed = $this->iiifParser->parseIiifUuidImageFromFilename($candidate);
            if ($parsed !== null) {
                $pathsToTry[] = $imagesRoot.DIRECTORY_SEPARATOR.$parsed['storage_basename'];
            }
        }

        if ($pathsToTry === []) {
            foreach (glob($imagesRoot.DIRECTORY_SEPARATOR.$webResourceId.'.*') ?: [] as $globPath) {
                $pathsToTry[] = $globPath;
            }
        }

        $pathsToTry = array_values(array_unique($pathsToTry));

        foreach ($pathsToTry as $path) {
            if (! is_file($path)) {
                continue;
            }
            if (@unlink($path) === false) {
                throw new RuntimeException("Impossibile eliminare il file immagine: {$path}");
            }
        }
    }
}
