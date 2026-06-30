<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MirrorInstance;
use App\Services\MirrorSchemaService;
use App\Support\IngestionPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MirrorInstanceObserver
{
    public function __construct(
        private readonly MirrorSchemaService $mirrorSchemaService
    ) {
    }

    /**
     * Handle the MirrorInstance "creating" event.
     * Crea lo schema PostgreSQL prima di salvare il record.
     */
    public function creating(MirrorInstance $mirrorInstance): void
    {
        Log::info("MirrorInstanceObserver::creating called", [
            'name' => $mirrorInstance->name,
            'display_name' => $mirrorInstance->display_name ?? null,
        ]);

        try {
            // Crea lo schema PostgreSQL prima di salvare il record
            // Se fallisce, la transazione verrà rollbackata
            $this->mirrorSchemaService->createMirrorSchema($mirrorInstance->name);
            Log::info("Schema creation completed in Observer", ['name' => $mirrorInstance->name]);
        } catch (\Exception $e) {
            Log::error("Schema creation failed in Observer", [
                'name' => $mirrorInstance->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle the MirrorInstance "created" event.
     */
    public function created(MirrorInstance $mirrorInstance): void
    {
        Log::info("Mirror instance created", [
            'id' => $mirrorInstance->id,
            'name' => $mirrorInstance->name,
            'schema' => $mirrorInstance->name,
        ]);
    }

    /**
     * Handle the MirrorInstance "updated" event.
     */
    public function updated(MirrorInstance $mirrorInstance): void
    {
        // Non facciamo nulla qui, la modifica dei metadati non richiede
        // modifiche allo schema PostgreSQL
        Log::info("Mirror instance updated", [
            'id' => $mirrorInstance->id,
            'name' => $mirrorInstance->name,
        ]);
    }

    /**
     * Handle the MirrorInstance "deleting" event.
     * Elimina le cartelle sotto INGEST_FS_ROOT per ogni import_run dello schema, poi lo schema PostgreSQL.
     */
    public function deleting(MirrorInstance $mirrorInstance): void
    {
        // Verifica che l'istanza non sia protetta
        if ($mirrorInstance->is_protected) {
            throw new RuntimeException(
                "Cannot delete protected mirror instance: {$mirrorInstance->name}"
            );
        }

        // Prima di eliminare lo schema: eliminare tutte le cartelle (nome = import_run_id) sotto INGEST_FS_ROOT
        $schemaName = $mirrorInstance->name;
        try {
            $runIds = DB::table($schemaName.'.import_run')->pluck('import_run_id');
            foreach ($runIds as $importRunId) {
                IngestionPaths::deleteRunExtractionPath((string) $importRunId);
            }
        } catch (\Throwable $e) {
            Log::warning('MirrorInstanceObserver: could not read import_run or delete run folders', [
                'schema' => $schemaName,
                'error' => $e->getMessage(),
            ]);
            // Procedi comunque con la cancellazione dello schema (es. tabella import_run inesistente)
        }

        // Elimina lo schema PostgreSQL
        // Se fallisce, la transazione verrà rollbackata
        $this->mirrorSchemaService->deleteMirrorSchema($mirrorInstance->name);
    }

    /**
     * Handle the MirrorInstance "deleted" event.
     */
    public function deleted(MirrorInstance $mirrorInstance): void
    {
        Log::info("Mirror instance deleted", [
            'id' => $mirrorInstance->id,
            'name' => $mirrorInstance->name,
            'schema' => $mirrorInstance->name,
        ]);
    }

    /**
     * Handle the MirrorInstance "restored" event.
     */
    public function restored(MirrorInstance $mirrorInstance): void
    {
        // Se si usa soft delete, qui si potrebbe ricreare lo schema
        // Per ora non implementato
    }

    /**
     * Handle the MirrorInstance "force deleted" event.
     */
    public function forceDeleted(MirrorInstance $mirrorInstance): void
    {
        // Stessa logica di deleting
        if (! $mirrorInstance->is_protected) {
            $this->mirrorSchemaService->deleteMirrorSchema($mirrorInstance->name);
        }
    }
}
