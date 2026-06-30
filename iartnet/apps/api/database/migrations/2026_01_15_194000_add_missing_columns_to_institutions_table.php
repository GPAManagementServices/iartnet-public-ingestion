<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verifica che la tabella esista
        $tableExists = DB::selectOne(
            "SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'iartnet_master' 
                AND table_name = 'institutions'
            ) as exists"
        );

        if (! ($tableExists->exists ?? false)) {
            // Se la tabella non esiste, la creiamo completamente
            DB::statement('
                CREATE TABLE iartnet_master.institutions (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    name VARCHAR(255) NOT NULL,
                    code VARCHAR(50) NOT NULL UNIQUE,
                    description TEXT,
                    address VARCHAR(500),
                    email VARCHAR(255),
                    phone VARCHAR(50),
                    website VARCHAR(255),
                    is_active BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
                )
            ');
        } else {
            // Se la tabella esiste, aggiungiamo solo le colonne mancanti
            $columns = DB::select(
                "SELECT column_name 
                 FROM information_schema.columns 
                 WHERE table_schema = 'iartnet_master' 
                 AND table_name = 'institutions'"
            );

            $existingColumns = array_map(fn ($col) => $col->column_name, $columns);

            // Aggiungi description se mancante
            if (! in_array('description', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN description TEXT
                ');
            }

            // Aggiungi address se mancante
            if (! in_array('address', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN address VARCHAR(500)
                ');
            }

            // Aggiungi email se mancante
            if (! in_array('email', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN email VARCHAR(255)
                ');
            }

            // Aggiungi phone se mancante
            if (! in_array('phone', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN phone VARCHAR(50)
                ');
            }

            // Aggiungi website se mancante
            if (! in_array('website', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN website VARCHAR(255)
                ');
            }

            // Aggiungi is_active se mancante
            if (! in_array('is_active', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE
                ');
            }

            // Aggiungi created_at se mancante
            if (! in_array('created_at', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
                ');
            }

            // Aggiungi updated_at se mancante
            if (! in_array('updated_at', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
                ');
            }

            // Verifica che code esista e sia unique
            if (! in_array('code', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN code VARCHAR(50) UNIQUE
                ');
            }

            // Verifica che name esista
            if (! in_array('name', $existingColumns, true)) {
                DB::statement('
                    ALTER TABLE iartnet_master.institutions 
                    ADD COLUMN name VARCHAR(255) NOT NULL
                ');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non rimuoviamo le colonne per sicurezza
        // Se necessario, creare una migration separata per rimuoverle
    }
};
