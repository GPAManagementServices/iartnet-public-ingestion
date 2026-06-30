<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Schema public: aggiunge flag_institution e institution_id a users.
     * Allineamento codice Laravel allo script SQL 20260611_01_users_institution_association.sql.
     * Le modifiche al DB in produzione vanno applicate con lo script SQL dedicato.
     */
    protected $connection = 'pgsql_public';

    public function up(): void
    {
        $columns = DB::connection($this->connection)->select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name = 'users'"
        );

        $existingColumns = array_map(fn ($col) => $col->column_name, $columns);

        if (! in_array('flag_institution', $existingColumns, true)) {
            DB::connection($this->connection)->statement('
                ALTER TABLE public.users
                ADD COLUMN flag_institution boolean NOT NULL DEFAULT false
            ');
        }

        if (! in_array('institution_id', $existingColumns, true)) {
            DB::connection($this->connection)->statement('
                ALTER TABLE public.users
                ADD COLUMN institution_id uuid NULL
            ');
        }

        DB::connection($this->connection)->statement('
            CREATE INDEX IF NOT EXISTS idx_users_institution_id
            ON public.users (institution_id)
        ');

        $fkExists = DB::connection($this->connection)->selectOne(
            "SELECT EXISTS (
                SELECT 1
                FROM information_schema.table_constraints
                WHERE constraint_schema = 'public'
                  AND table_name = 'users'
                  AND constraint_name = 'fk_users_institution_id'
            ) as exists"
        );

        if (! ($fkExists->exists ?? false)) {
            DB::connection($this->connection)->statement('
                ALTER TABLE public.users
                ADD CONSTRAINT fk_users_institution_id
                FOREIGN KEY (institution_id)
                REFERENCES iartnet_master.institutions (id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
            ');
        }

        $checkExists = DB::connection($this->connection)->selectOne(
            "SELECT EXISTS (
                SELECT 1
                FROM information_schema.table_constraints
                WHERE constraint_schema = 'public'
                  AND table_name = 'users'
                  AND constraint_name = 'chk_users_institution_consistency'
            ) as exists"
        );

        if (! ($checkExists->exists ?? false)) {
            DB::connection($this->connection)->statement('
                ALTER TABLE public.users
                ADD CONSTRAINT chk_users_institution_consistency
                CHECK (
                    (flag_institution = false AND institution_id IS NULL)
                    OR (flag_institution = true AND institution_id IS NOT NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE public.users DROP CONSTRAINT IF EXISTS chk_users_institution_consistency
        ');
        DB::connection($this->connection)->statement('
            ALTER TABLE public.users DROP CONSTRAINT IF EXISTS fk_users_institution_id
        ');
        DB::connection($this->connection)->statement('
            DROP INDEX IF EXISTS public.idx_users_institution_id
        ');
        DB::connection($this->connection)->statement('
            ALTER TABLE public.users DROP COLUMN IF EXISTS institution_id
        ');
        DB::connection($this->connection)->statement('
            ALTER TABLE public.users DROP COLUMN IF EXISTS flag_institution
        ');
    }
};
