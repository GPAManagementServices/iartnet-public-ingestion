<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MirrorSchemaService
{
    /**
     * Template schema name to clone from.
     */
    private const TEMPLATE_SCHEMA = 'mirror_iccd';

    /**
     * Quote an identifier for PostgreSQL (uses double quotes).
     *
     * @param  string  $identifier  The identifier to quote
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        // Escape virgolette doppie e quotare l'identificatore
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * Create a new mirror schema by cloning the template schema.
     *
     * @param  string  $schemaName  The name of the new schema to create
     * @return void
     *
     * @throws RuntimeException If schema creation or cloning fails
     */
    public function createMirrorSchema(string $schemaName): void
    {
        // Valida il nome dello schema (solo caratteri alfanumerici e underscore)
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $schemaName)) {
            throw new RuntimeException(
                "Invalid schema name: {$schemaName}. Schema names must start with a letter and contain only lowercase letters, numbers, and underscores."
            );
        }

        Log::info("Starting mirror schema creation", ['schema_name' => $schemaName]);

        try {
            // Verifica che lo schema template esista
            $templateExists = DB::selectOne(
                "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
                [self::TEMPLATE_SCHEMA]
            );

            if (! ($templateExists->exists ?? false)) {
                throw new RuntimeException(
                    "Template schema '".self::TEMPLATE_SCHEMA."' does not exist."
                );
            }

            Log::info("Template schema verified", ['template_schema' => self::TEMPLATE_SCHEMA]);

            // Verifica che lo schema non esista già
            $schemaExists = DB::selectOne(
                "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
                [$schemaName]
            );

            if ($schemaExists->exists ?? false) {
                throw new RuntimeException(
                    "Schema '{$schemaName}' already exists."
                );
            }

            Log::info("Schema name is available", ['schema_name' => $schemaName]);

            // Nota: CREATE SCHEMA è una DDL statement che viene automaticamente committata
            // Non può essere rollbackata, quindi dobbiamo gestire gli errori con cleanup manuale
            DB::statement("CREATE SCHEMA ".$this->quoteIdentifier($schemaName));

            Log::info("Schema created", ['schema_name' => $schemaName]);

            // Clona tutte le tabelle dallo schema template
            $this->cloneTablesFromTemplate($schemaName);

            Log::info("Tables cloned", ['schema_name' => $schemaName]);

            // Clona tutte le sequenze
            $this->cloneSequencesFromTemplate($schemaName);

            Log::info("Sequences cloned", ['schema_name' => $schemaName]);

            // Clona tutte le funzioni e procedure
            $this->cloneFunctionsFromTemplate($schemaName);

            Log::info("Functions cloned", ['schema_name' => $schemaName]);

            // Clona tutti i tipi personalizzati
            $this->cloneTypesFromTemplate($schemaName);

            Log::info("Types cloned", ['schema_name' => $schemaName]);

            Log::info("Mirror schema '{$schemaName}' created successfully from template '".self::TEMPLATE_SCHEMA."'");
        } catch (\Exception $e) {
            // Tenta di eliminare lo schema se è stato creato parzialmente
            try {
                $schemaExists = DB::selectOne(
                    "SELECT EXISTS(SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as exists",
                    [$schemaName]
                );

                if ($schemaExists->exists ?? false) {
                    Log::warning("Cleaning up partially created schema", ['schema_name' => $schemaName]);
                    DB::statement("DROP SCHEMA IF EXISTS ".$this->quoteIdentifier($schemaName)." CASCADE");
                    Log::info("Schema cleanup completed", ['schema_name' => $schemaName]);
                }
            } catch (\Exception $cleanupException) {
                Log::error("Failed to cleanup schema '{$schemaName}' after error", [
                    'error' => $cleanupException->getMessage(),
                    'original_error' => $e->getMessage(),
                ]);
            }

            Log::error("Failed to create mirror schema '{$schemaName}'", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new RuntimeException(
                "Failed to create mirror schema '{$schemaName}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Delete a mirror schema.
     *
     * @param  string  $schemaName  The name of the schema to delete
     * @return void
     *
     * @throws RuntimeException If schema deletion fails
     */
    public function deleteMirrorSchema(string $schemaName): void
    {
        // Valida il nome dello schema
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $schemaName)) {
            throw new RuntimeException(
                "Invalid schema name: {$schemaName}."
            );
        }

        // Protegge gli schemi di sistema
        if (in_array($schemaName, [self::TEMPLATE_SCHEMA, 'iartnet_master', 'public', 'information_schema', 'pg_catalog'], true)) {
            throw new RuntimeException(
                "Cannot delete protected schema: {$schemaName}."
            );
        }

        try {
            DB::statement("DROP SCHEMA IF EXISTS ".$this->quoteIdentifier($schemaName)." CASCADE");

            Log::info("Mirror schema '{$schemaName}' deleted successfully");
        } catch (\Exception $e) {
            Log::error("Failed to delete mirror schema '{$schemaName}'", [
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Failed to delete mirror schema '{$schemaName}': ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Clone all tables from template schema to new schema.
     *
     * @param  string  $targetSchema  The target schema name
     * @return void
     */
    private function cloneTablesFromTemplate(string $targetSchema): void
    {
        // Ottieni tutte le tabelle dallo schema template
        $tables = DB::select(
            "SELECT table_name 
             FROM information_schema.tables 
             WHERE table_schema = ? 
             AND table_type = 'BASE TABLE'",
            [self::TEMPLATE_SCHEMA]
        );

        foreach ($tables as $table) {
            $tableName = $table->table_name;

            // Crea la tabella con la stessa struttura
            DB::statement("
                CREATE TABLE ".$this->quoteIdentifier($targetSchema).".".$this->quoteIdentifier($tableName)." 
                (LIKE ".$this->quoteIdentifier(self::TEMPLATE_SCHEMA).".".$this->quoteIdentifier($tableName)." INCLUDING ALL)
            ");

            // Copia i dati (opzionale, ma utile per avere una struttura completa)
            // DB::statement("
            //     INSERT INTO {$targetSchema}.{$tableName} 
            //     SELECT * FROM ".self::TEMPLATE_SCHEMA.".{$tableName}
            // ");
        }
    }

    /**
     * Clone all sequences from template schema to new schema.
     *
     * @param  string  $targetSchema  The target schema name
     * @return void
     */
    private function cloneSequencesFromTemplate(string $targetSchema): void
    {
        $sequences = DB::select(
            "SELECT sequence_name 
             FROM information_schema.sequences 
             WHERE sequence_schema = ?",
            [self::TEMPLATE_SCHEMA]
        );

        foreach ($sequences as $sequence) {
            $sequenceName = $sequence->sequence_name;

            // Ottieni i dettagli della sequenza
            $sequenceInfo = DB::selectOne(
                "SELECT last_value, is_called 
                 FROM ".$this->quoteIdentifier(self::TEMPLATE_SCHEMA).".".$this->quoteIdentifier($sequenceName)
            );

            // Crea la sequenza nel nuovo schema
            DB::statement("
                CREATE SEQUENCE ".$this->quoteIdentifier($targetSchema).".".$this->quoteIdentifier($sequenceName)."
                START WITH ".($sequenceInfo->last_value ?? 1)."
            ");
        }
    }

    /**
     * Clone all functions and procedures from template schema to new schema.
     *
     * @param  string  $targetSchema  The target schema name
     * @return void
     */
    private function cloneFunctionsFromTemplate(string $targetSchema): void
    {
        $functions = DB::select(
            "SELECT 
                p.proname as function_name,
                pg_get_functiondef(p.oid) as function_definition
             FROM pg_proc p
             JOIN pg_namespace n ON p.pronamespace = n.oid
             WHERE n.nspname = ?",
            [self::TEMPLATE_SCHEMA]
        );

        foreach ($functions as $function) {
            // Sostituisci il nome dello schema nella definizione
            $definition = str_replace(
                self::TEMPLATE_SCHEMA,
                $targetSchema,
                $function->function_definition
            );

            try {
                DB::statement($definition);
            } catch (\Exception $e) {
                // Log dell'errore ma continua con le altre funzioni
                Log::warning("Failed to clone function '{$function->function_name}'", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Clone all custom types from template schema to new schema.
     *
     * @param  string  $targetSchema  The target schema name
     * @return void
     */
    private function cloneTypesFromTemplate(string $targetSchema): void
    {
        // Ottieni tutti i tipi compositi (composite types) dallo schema template
        $types = DB::select(
            "SELECT 
                t.typname as type_name,
                string_agg(
                    a.attname || ' ' || pg_catalog.format_type(a.atttypid, a.atttypmod),
                    ', ' ORDER BY a.attnum
                ) as type_definition
             FROM pg_type t
             JOIN pg_namespace n ON t.typnamespace = n.oid
             JOIN pg_class c ON t.typrelid = c.oid
             LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
             WHERE n.nspname = ?
             AND t.typtype = 'c'
             GROUP BY t.typname",
            [self::TEMPLATE_SCHEMA]
        );

        foreach ($types as $type) {
            if (empty($type->type_definition)) {
                continue;
            }

            try {
                // Crea il tipo composito nel nuovo schema
                DB::statement("
                    CREATE TYPE ".$this->quoteIdentifier($targetSchema).".".$this->quoteIdentifier($type->type_name)." AS (
                        {$type->type_definition}
                    )
                ");
            } catch (\Exception $e) {
                // Log dell'errore ma continua con gli altri tipi
                Log::warning("Failed to clone type '{$type->type_name}'", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
