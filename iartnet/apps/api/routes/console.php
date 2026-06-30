<?php

use App\Jobs\TranslateRecordJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Translation worker: invia job ogni minuto se la flag è attiva.
| Micro-batching: si accodano 50 job per saturare Redis; il worker li consuma
| in sequenza e FOR UPDATE SKIP LOCKED nel Job garantisce un record univoco per job,
| evitando idle di ~59 secondi tra un run e il successivo.
|--------------------------------------------------------------------------
*/
Schedule::call(function () {
    if (Cache::get(TranslateRecordJob::CACHE_KEY_ENABLED, false)) {
        for ($i = 0; $i < 50; $i++) {
            TranslateRecordJob::dispatch();
        }
    }
})->everyMinute();

/*
|--------------------------------------------------------------------------
| Ingestion: pulizia run non proseguiti (extraction + tmp) dopo 1 minuto.
|--------------------------------------------------------------------------
*/
Schedule::command('ingestion:clean-pending')->everyMinute();
