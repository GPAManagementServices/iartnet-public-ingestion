-- =============================================================================
-- 20260611_01_users_institution_association.sql
-- Target: PostgreSQL 14+
-- Schema: public.users
--
-- Aggiunge l'associazione opzionale tra User e Institution.
--
-- Mapping requisiti -> colonne DB (convenzione snake_case PostgreSQL/Laravel):
--   flagInstitution (bool) -> flag_institution
--   institutionId   (uuid) -> institution_id  (FK -> iartnet_master.institutions.id)
--
-- Applicazione (esempio):
--   psql -h <host> -U <user> -d <database> -f 20260611_01_users_institution_association.sql
--
-- Rollback: vedi sezione ROLLBACK in fondo al file.
-- =============================================================================

BEGIN;

-- flag_institution: true se lo User è associato a un'istituzione
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'users'
          AND column_name = 'flag_institution'
    ) THEN
        ALTER TABLE public.users
            ADD COLUMN flag_institution boolean NOT NULL DEFAULT false;
    END IF;
END $$;

-- institution_id: UUID dell'istituzione (nullable quando flag_institution = false)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'users'
          AND column_name = 'institution_id'
    ) THEN
        ALTER TABLE public.users
            ADD COLUMN institution_id uuid NULL;
    END IF;
END $$;

-- Indice per lookup per istituzione
CREATE INDEX IF NOT EXISTS idx_users_institution_id
    ON public.users (institution_id);

-- FK verso iartnet_master.institutions
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'users'
          AND constraint_name = 'fk_users_institution_id'
    ) THEN
        ALTER TABLE public.users
            ADD CONSTRAINT fk_users_institution_id
            FOREIGN KEY (institution_id)
            REFERENCES iartnet_master.institutions (id)
            ON DELETE RESTRICT
            ON UPDATE CASCADE;
    END IF;
END $$;

-- Coerenza dati:
--   flag_institution = false  -> institution_id deve essere NULL
--   flag_institution = true   -> institution_id deve essere valorizzato
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.table_constraints
        WHERE constraint_schema = 'public'
          AND table_name = 'users'
          AND constraint_name = 'chk_users_institution_consistency'
    ) THEN
        ALTER TABLE public.users
            ADD CONSTRAINT chk_users_institution_consistency
            CHECK (
                (flag_institution = false AND institution_id IS NULL)
                OR (flag_institution = true AND institution_id IS NOT NULL)
            );
    END IF;
END $$;

COMMIT;

-- =============================================================================
-- ROLLBACK (eseguire manualmente se necessario)
-- =============================================================================
-- BEGIN;
-- ALTER TABLE public.users DROP CONSTRAINT IF EXISTS chk_users_institution_consistency;
-- ALTER TABLE public.users DROP CONSTRAINT IF EXISTS fk_users_institution_id;
-- DROP INDEX IF EXISTS public.idx_users_institution_id;
-- ALTER TABLE public.users DROP COLUMN IF EXISTS institution_id;
-- ALTER TABLE public.users DROP COLUMN IF EXISTS flag_institution;
-- COMMIT;
