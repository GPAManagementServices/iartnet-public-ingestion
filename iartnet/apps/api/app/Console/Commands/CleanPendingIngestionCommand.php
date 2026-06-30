<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\IngestionPaths;
use App\Support\IngestionPendingCleanup;
use Illuminate\Console\Command;

class CleanPendingIngestionCommand extends Command
{
    protected $signature = 'ingestion:clean-pending';

    protected $description = 'Elimina cartelle extraction e tmp per run in pending cleanup scaduti (timeout 10 min senza proseguimento)';

    public function handle(): int
    {
        $expired = IngestionPendingCleanup::getExpiredRunIds();
        if (empty($expired)) {
            return self::SUCCESS;
        }

        foreach ($expired as $runId) {
            IngestionPaths::deleteExtractionAndTmp($runId);
        }
        IngestionPendingCleanup::remove($expired);
        $this->info('Cleaned '.count($expired).' pending ingestion run(s): '.implode(', ', $expired));

        return self::SUCCESS;
    }
}
