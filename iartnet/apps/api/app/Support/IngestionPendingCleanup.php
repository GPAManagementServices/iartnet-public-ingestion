<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gestione "pending cleanup" per ingestion: runId registrati dopo l'estrazione (step 2)
 * vengono eliminati (extraction + tmp) se entro un timeout (es. 1 min) l'utente non prosegue.
 * Chiave cache: ingestion.pending_cleanup => [runId => unix timestamp]
 */
class IngestionPendingCleanup
{
    public const CACHE_KEY = 'ingestion.pending_cleanup';

    /** Timeout in secondi dopo il quale eseguire la pulizia se l'utente non ha proseguito (10 minuti). */
    public const TIMEOUT_SECONDS = 600;

    /**
     * Registra un runId per la pulizia differita (chiamato alla fine dello step 2 - validazione contenuto zip).
     */
    public static function register(string $runId): void
    {
        $pending = self::getAll();
        $pending[$runId] = time();
        Cache::put(self::CACHE_KEY, $pending, now()->addDays(1));
        Log::debug('Ingestion pending cleanup: registered', ['run_id' => $runId]);
    }

    /**
     * Rimuove un runId dalla lista pending (chiamato quando l'utente prosegue a validazione o import).
     */
    public static function clear(string $runId): void
    {
        $pending = self::getAll();
        unset($pending[$runId]);
        Cache::put(self::CACHE_KEY, $pending, now()->addDays(1));
        Log::debug('Ingestion pending cleanup: cleared', ['run_id' => $runId]);
    }

    /**
     * Restituisce i runId scaduti (timestamp più vecchio di TIMEOUT_SECONDS).
     *
     * @return array<string>
     */
    public static function getExpiredRunIds(): array
    {
        $cutoff = time() - self::TIMEOUT_SECONDS;
        $pending = self::getAll();
        $expired = [];
        foreach ($pending as $runId => $ts) {
            if ($ts < $cutoff) {
                $expired[] = $runId;
            }
        }
        return $expired;
    }

    /**
     * @return array<string, int>
     */
    private static function getAll(): array
    {
        $v = Cache::get(self::CACHE_KEY, []);
        return is_array($v) ? $v : [];
    }

    /**
     * Rimuove i runId dalla lista pending (dopo aver eseguito la pulizia).
     *
     * @param  array<string>  $runIds
     */
    public static function remove(array $runIds): void
    {
        if (empty($runIds)) {
            return;
        }
        $pending = self::getAll();
        foreach ($runIds as $id) {
            unset($pending[$id]);
        }
        Cache::put(self::CACHE_KEY, $pending, now()->addDays(1));
    }
}
