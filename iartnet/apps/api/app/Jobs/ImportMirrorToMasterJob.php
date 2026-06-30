<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Import\MirrorToMasterImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ImportMirrorToMasterJob
 *
 * Job per eseguire l'importazione Mirror → Master in background.
 * Gestisce logging ed errori.
 */
class ImportMirrorToMasterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nome dello schema Mirror.
     */
    private string $mirrorSchema;

    /**
     * Nome del file di mapping.
     */
    private string $mappingFile;

    /**
     * ID dell'istituzione primaria.
     */
    private string $institutionId;

    /**
     * Filtro opzionale: importa solo record con questo normativa_code (es. 'MARC21', 'JSON').
     */
    private ?string $normativaCode;

    /**
     * Numero di tentativi in caso di errore.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Timeout del job in secondi.
     *
     * @var int
     */
    public int $timeout = 3600; // 1 ora

    /**
     * Costruttore.
     *
     * @param  string  $mirrorSchema  Nome dello schema Mirror
     * @param  string  $mappingFile  Nome del file di mapping
     * @param  string  $institutionId  ID dell'istituzione primaria
     * @param  string|null  $normativaCode  Se impostato, importa solo record con questo normativa_code (es. 'MARC21', 'JSON')
     */
    public function __construct(
        string $mirrorSchema,
        string $mappingFile,
        string $institutionId,
        ?string $normativaCode = null
    ) {
        $this->mirrorSchema = $mirrorSchema;
        $this->mappingFile = $mappingFile;
        $this->institutionId = $institutionId;
        $this->normativaCode = $normativaCode;
    }

    /**
     * Esegue il job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('ImportMirrorToMasterJob: Starting', [
            'mirror_schema' => $this->mirrorSchema,
            'mapping_file' => $this->mappingFile,
            'institution_id' => $this->institutionId,
            'normativa_code' => $this->normativaCode,
        ]);

        try {
            $importer = new MirrorToMasterImporter(
                $this->mirrorSchema,
                $this->mappingFile,
                $this->institutionId,
                $this->normativaCode
            );

            $stats = $importer->importAll();

            Log::info('ImportMirrorToMasterJob: Completed successfully', $stats);
        } catch (\Exception $e) {
            Log::error('ImportMirrorToMasterJob: Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Rilancia per permettere retry
        }
    }

    /**
     * Gestisce il fallimento del job.
     *
     * @param  \Throwable  $exception  Eccezione sollevata
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ImportMirrorToMasterJob: Permanently failed', [
            'mirror_schema' => $this->mirrorSchema,
            'mapping_file' => $this->mappingFile,
            'institution_id' => $this->institutionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
