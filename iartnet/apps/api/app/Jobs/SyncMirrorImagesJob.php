<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\MirrorImageSyncMode;
use App\Services\Mirror\MirrorImageSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job asincrono per Synchronize Images (Mirror → Master).
 * Supporta modalità copy (copia diretta) e vips (preparazione IIIF).
 */
class SyncMirrorImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        private readonly string $mirrorSchema,
        private readonly string $institutionId,
        private readonly MirrorImageSyncMode $mode,
    ) {
        $this->timeout = (int) config('images.vips_job_timeout', 3600);
    }

    public function handle(MirrorImageSyncService $syncService): void
    {
        Log::info('SyncMirrorImagesJob: starting', [
            'mirror_schema' => $this->mirrorSchema,
            'institution_id' => $this->institutionId,
            'mode' => $this->mode->value,
        ]);

        $result = $syncService->execute(
            $this->mirrorSchema,
            $this->institutionId,
            $this->mode
        );

        if ($result['error'] !== null) {
            Log::error('SyncMirrorImagesJob: failed', $result);
            throw new \RuntimeException((string) $result['error']);
        }

        Log::info('SyncMirrorImagesJob: completed', [
            'mirror_schema' => $this->mirrorSchema,
            'mode' => $this->mode->value,
            'processed' => $result['processed'],
            'success_count' => $result['success_count'],
            'error_count' => $result['error_count'],
            'skipped_count' => $result['skipped_count'],
        ]);

        if ($result['error_count'] > 0) {
            Log::warning('SyncMirrorImagesJob: completed with errors', [
                'error_details' => $result['error_details'],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncMirrorImagesJob: permanently failed', [
            'mirror_schema' => $this->mirrorSchema,
            'institution_id' => $this->institutionId,
            'mode' => $this->mode->value,
            'error' => $exception->getMessage(),
        ]);
    }
}
