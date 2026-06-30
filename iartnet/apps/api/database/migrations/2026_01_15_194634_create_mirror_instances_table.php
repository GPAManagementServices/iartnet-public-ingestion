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
        // Verifica che lo schema iartnet_master esista
        //DB::statement('CREATE SCHEMA IF NOT EXISTS iartnet_master');

        // Elimina la tabella se esiste già (per recupero da errori precedenti)
        DB::statement('DROP TABLE IF EXISTS iartnet_master.mirror_instances CASCADE');

        // Crea la tabella nello schema iartnet_master
        DB::statement('
            CREATE TABLE iartnet_master.mirror_instances (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                display_name VARCHAR(255) NOT NULL,
                description TEXT,
                institution_id UUID NOT NULL,
                is_protected BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        ');

        // Crea l'indice per institution_id
        DB::statement('
            CREATE INDEX idx_mirror_instances_institution_id 
            ON iartnet_master.mirror_instances(institution_id)
        ');

        // Aggiunge la foreign key verso institutions
        DB::statement('
            ALTER TABLE iartnet_master.mirror_instances
            ADD CONSTRAINT fk_mirror_instances_institution_id
            FOREIGN KEY (institution_id)
            REFERENCES iartnet_master.institutions(id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rimuove la foreign key
        DB::statement('
            ALTER TABLE iartnet_master.mirror_instances
            DROP CONSTRAINT IF EXISTS fk_mirror_instances_institution_id
        ');

        // Elimina la tabella
        DB::statement('DROP TABLE IF EXISTS iartnet_master.mirror_instances');
    }
};
