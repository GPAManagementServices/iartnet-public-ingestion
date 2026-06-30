-- file: 20260226_00_fts_published_fallback_it_FULL.sql
-- IARTNET - Full Text Search (EN with fallback IT) - FULL migration (Slice1 + Slice2 merged)
-- Target: PostgreSQL 18
--
-- Features
-- - Search ONLY published records
-- - Primary search in EN; if EN missing for a record -> fallback to IT
-- - Prefix search on first 3 characters (tokens length >= 3)
-- - Fuzzy match for tokens length >= 5 with error < 10% (Levenshtein), constrained to same prefix3
-- - Ranking: 0.75*FTS + 0.25*Fuzzy
-- - Snippet highlighting via ts_headline
-- - Incremental maintenance via triggers (records, i18n_texts, record_* relations)
--
-- Open Source only:
--   - unaccent (contrib)
--   - fuzzystrmatch (contrib)
--
-- NOTE: Run once. It is idempotent (safe to re-run).

BEGIN;

-- --------------------------------------------------------------------
-- 0) Extensions (Open Source / contrib)
-- --------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public;
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch WITH SCHEMA public;

-- --------------------------------------------------------------------
-- 1) Search index table (one row per record) - EN + IT
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS iartnet_master.record_search_en (
  record_id uuid PRIMARY KEY,
  stable_id text NOT NULL,
  primary_institution_id uuid NOT NULL,
  publish_state iartnet_master.publish_state NOT NULL,

  -- EN
  title_en text,
  description_en text,
  content_en text NOT NULL DEFAULT '',
  tsv_en tsvector,
  lexemes_en text[] NOT NULL DEFAULT ARRAY[]::text[],
  has_en boolean NOT NULL DEFAULT false,

  -- IT (fallback)
  title_it text,
  description_it text,
  content_it text NOT NULL DEFAULT '',
  tsv_it tsvector,
  lexemes_it text[] NOT NULL DEFAULT ARRAY[]::text[],
  has_it boolean NOT NULL DEFAULT false,

  updated_at timestamptz NOT NULL DEFAULT now()
);

-- Base indexes
CREATE INDEX IF NOT EXISTS record_search_en_tsv_en_gin
  ON iartnet_master.record_search_en USING gin(tsv_en);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname='iartnet_master'
      AND indexname='record_search_en_tsv_it_gin'
  ) THEN
    EXECUTE 'CREATE INDEX record_search_en_tsv_it_gin ON iartnet_master.record_search_en USING gin(tsv_it)';
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS record_search_en_institution_idx
  ON iartnet_master.record_search_en (primary_institution_id);

CREATE INDEX IF NOT EXISTS record_search_en_publish_idx
  ON iartnet_master.record_search_en (publish_state);

-- Optional (usually beneficial): partial indexes for published only.
-- (They reduce index size and speed published-only searches.)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname='iartnet_master'
      AND indexname='record_search_en_tsv_en_pub_gin'
  ) THEN
    EXECUTE $q$
      CREATE INDEX record_search_en_tsv_en_pub_gin
      ON iartnet_master.record_search_en USING gin(tsv_en)
      WHERE publish_state='published' AND has_en
    $q$;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname='iartnet_master'
      AND indexname='record_search_en_tsv_it_pub_gin'
  ) THEN
    EXECUTE $q$
      CREATE INDEX record_search_en_tsv_it_pub_gin
      ON iartnet_master.record_search_en USING gin(tsv_it)
      WHERE publish_state='published' AND (NOT has_en) AND has_it
    $q$;
  END IF;
END $$;

-- --------------------------------------------------------------------
-- 2) Helper: best i18n text per entity/field/lang by status and updated_at
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.fn_best_i18n_text_lang(
  p_entity_type text,
  p_entity_id uuid,
  p_field_name text,
  p_lang text
) RETURNS text
LANGUAGE sql
STABLE
SET search_path = iartnet_master, public
AS $$
  SELECT t.text_value
  FROM iartnet_master.i18n_texts t
  WHERE t.entity_type = p_entity_type
    AND t.entity_id   = p_entity_id
    AND t.field_name  = p_field_name
    AND t.lang        = p_lang
  ORDER BY
    CASE t.status
      WHEN 'approved' THEN 0
      WHEN 'reviewed' THEN 1
      WHEN 'machine'  THEN 2
      ELSE 3
    END,
    t.updated_at DESC
  LIMIT 1;
$$;

-- --------------------------------------------------------------------
-- 3) Build/Upsert a single record into record_search_en (EN + IT)
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.record_search_en_build(p_record_id uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
DECLARE
  -- EN
  v_title_en text;
  v_desc_en  text;
  v_other_en text;
  v_agents_en text;
  v_concepts_en text;
  v_places_en text;
  v_content_en text;
  v_tsv_en tsvector;
  v_lexemes_en text[];
  v_has_en boolean;

  -- IT
  v_title_it text;
  v_desc_it  text;
  v_other_it text;
  v_agents_it text;
  v_concepts_it text;
  v_places_it text;
  v_content_it text;
  v_tsv_it tsvector;
  v_lexemes_it text[];
  v_has_it boolean;
BEGIN
  -- record deleted => purge
  IF NOT EXISTS (SELECT 1 FROM iartnet_master.records r WHERE r.id = p_record_id) THEN
    DELETE FROM iartnet_master.record_search_en WHERE record_id = p_record_id;
    RETURN;
  END IF;

  -- ---------------------- EN ----------------------
  v_title_en := iartnet_master.fn_best_i18n_text_lang('record', p_record_id, 'title', 'en');
  v_desc_en  := iartnet_master.fn_best_i18n_text_lang('record', p_record_id, 'description', 'en');

  SELECT string_agg(t.text_value, E'\n' ORDER BY t.field_name)
  INTO v_other_en
  FROM (
    SELECT DISTINCT ON (t.field_name)
      t.field_name, t.text_value, t.status, t.updated_at
    FROM iartnet_master.i18n_texts t
    WHERE t.entity_type='record'
      AND t.entity_id=p_record_id
      AND t.lang='en'
      AND t.field_name NOT IN ('title','description')
    ORDER BY t.field_name,
      CASE t.status
        WHEN 'approved' THEN 0
        WHEN 'reviewed' THEN 1
        WHEN 'machine'  THEN 2
        ELSE 3
      END,
      t.updated_at DESC
  ) t;

  -- Agents EN
  SELECT string_agg(x.label, '; ' ORDER BY x.ord)
  INTO v_agents_en
  FROM (
    SELECT ra.ord,
           COALESCE(
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'pref_label', 'en'),
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'name', 'en'),
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'label', 'en')
           ) AS label
    FROM iartnet_master.record_agents ra
    WHERE ra.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  -- Concepts EN
  SELECT string_agg(x.label, '; ')
  INTO v_concepts_en
  FROM (
    SELECT COALESCE(
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'pref_label', 'en'),
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'name', 'en'),
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'label', 'en')
    ) AS label
    FROM iartnet_master.record_concepts rc
    WHERE rc.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  -- Places EN
  SELECT string_agg(x.label, '; ')
  INTO v_places_en
  FROM (
    SELECT COALESCE(
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'pref_label', 'en'),
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'name', 'en'),
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'label', 'en')
    ) AS label
    FROM iartnet_master.record_places rp
    WHERE rp.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  v_content_en :=
    trim(regexp_replace(
      concat_ws(' ', v_title_en, v_desc_en, v_other_en, v_agents_en, v_concepts_en, v_places_en),
      '\s+',' ','g'
    ));
  v_has_en := coalesce(length(v_content_en),0) > 0;

  v_tsv_en :=
      setweight(to_tsvector('english', public.unaccent(coalesce(v_title_en,''))), 'A')
   || setweight(to_tsvector('english', public.unaccent(coalesce(v_desc_en,''))),  'B')
   || setweight(to_tsvector('english', public.unaccent(coalesce(concat_ws(' ', v_other_en, v_agents_en, v_concepts_en, v_places_en),''))), 'C');

  v_lexemes_en := coalesce(tsvector_to_array(v_tsv_en), ARRAY[]::text[]);

  -- ---------------------- IT ----------------------
  v_title_it := iartnet_master.fn_best_i18n_text_lang('record', p_record_id, 'title', 'it');
  v_desc_it  := iartnet_master.fn_best_i18n_text_lang('record', p_record_id, 'description', 'it');

  SELECT string_agg(t.text_value, E'\n' ORDER BY t.field_name)
  INTO v_other_it
  FROM (
    SELECT DISTINCT ON (t.field_name)
      t.field_name, t.text_value, t.status, t.updated_at
    FROM iartnet_master.i18n_texts t
    WHERE t.entity_type='record'
      AND t.entity_id=p_record_id
      AND t.lang='it'
      AND t.field_name NOT IN ('title','description')
    ORDER BY t.field_name,
      CASE t.status
        WHEN 'approved' THEN 0
        WHEN 'reviewed' THEN 1
        WHEN 'machine'  THEN 2
        ELSE 3
      END,
      t.updated_at DESC
  ) t;

  -- Agents IT
  SELECT string_agg(x.label, '; ' ORDER BY x.ord)
  INTO v_agents_it
  FROM (
    SELECT ra.ord,
           COALESCE(
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'pref_label', 'it'),
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'name', 'it'),
             iartnet_master.fn_best_i18n_text_lang('agent', ra.agent_id, 'label', 'it')
           ) AS label
    FROM iartnet_master.record_agents ra
    WHERE ra.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  -- Concepts IT
  SELECT string_agg(x.label, '; ')
  INTO v_concepts_it
  FROM (
    SELECT COALESCE(
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'pref_label', 'it'),
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'name', 'it'),
        iartnet_master.fn_best_i18n_text_lang('concept', rc.concept_id, 'label', 'it')
    ) AS label
    FROM iartnet_master.record_concepts rc
    WHERE rc.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  -- Places IT
  SELECT string_agg(x.label, '; ')
  INTO v_places_it
  FROM (
    SELECT COALESCE(
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'pref_label', 'it'),
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'name', 'it'),
      iartnet_master.fn_best_i18n_text_lang('place', rp.place_id, 'label', 'it')
    ) AS label
    FROM iartnet_master.record_places rp
    WHERE rp.record_id = p_record_id
  ) x
  WHERE x.label IS NOT NULL AND length(x.label) > 0;

  v_content_it :=
    trim(regexp_replace(
      concat_ws(' ', v_title_it, v_desc_it, v_other_it, v_agents_it, v_concepts_it, v_places_it),
      '\s+',' ','g'
    ));
  v_has_it := coalesce(length(v_content_it),0) > 0;

  v_tsv_it :=
      setweight(to_tsvector('italian', public.unaccent(coalesce(v_title_it,''))), 'A')
   || setweight(to_tsvector('italian', public.unaccent(coalesce(v_desc_it,''))),  'B')
   || setweight(to_tsvector('italian', public.unaccent(coalesce(concat_ws(' ', v_other_it, v_agents_it, v_concepts_it, v_places_it),''))), 'C');

  v_lexemes_it := coalesce(tsvector_to_array(v_tsv_it), ARRAY[]::text[]);

  -- UPSERT
  INSERT INTO iartnet_master.record_search_en (
    record_id, stable_id, primary_institution_id, publish_state,
    title_en, description_en, content_en, tsv_en, lexemes_en, has_en,
    title_it, description_it, content_it, tsv_it, lexemes_it, has_it,
    updated_at
  )
  SELECT
    r.id, r.stable_id, r.primary_institution_id, r.publish_state,
    v_title_en, v_desc_en, coalesce(v_content_en,''), v_tsv_en, v_lexemes_en, v_has_en,
    v_title_it, v_desc_it, coalesce(v_content_it,''), v_tsv_it, v_lexemes_it, v_has_it,
    now()
  FROM iartnet_master.records r
  WHERE r.id = p_record_id
  ON CONFLICT (record_id) DO UPDATE SET
    stable_id = EXCLUDED.stable_id,
    primary_institution_id = EXCLUDED.primary_institution_id,
    publish_state = EXCLUDED.publish_state,

    title_en = EXCLUDED.title_en,
    description_en = EXCLUDED.description_en,
    content_en = EXCLUDED.content_en,
    tsv_en = EXCLUDED.tsv_en,
    lexemes_en = EXCLUDED.lexemes_en,
    has_en = EXCLUDED.has_en,

    title_it = EXCLUDED.title_it,
    description_it = EXCLUDED.description_it,
    content_it = EXCLUDED.content_it,
    tsv_it = EXCLUDED.tsv_it,
    lexemes_it = EXCLUDED.lexemes_it,
    has_it = EXCLUDED.has_it,

    updated_at = EXCLUDED.updated_at;

END;
$$;

-- --------------------------------------------------------------------
-- 4) Rebuild ALL (set-based, fast, idempotent)
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.record_search_en_rebuild_all()
RETURNS bigint
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
DECLARE v_cnt bigint;
BEGIN
  TRUNCATE TABLE iartnet_master.record_search_en;

  WITH best_i18n AS (
    SELECT entity_type, entity_id, field_name, lang, text_value
    FROM (
      SELECT t.*,
        row_number() OVER (
          PARTITION BY t.entity_type, t.entity_id, t.field_name, t.lang
          ORDER BY
            CASE t.status
              WHEN 'approved' THEN 0
              WHEN 'reviewed' THEN 1
              WHEN 'machine'  THEN 2
              ELSE 3
            END,
            t.updated_at DESC
        ) AS rn
      FROM iartnet_master.i18n_texts t
      WHERE t.lang IN ('en','it')
    ) x
    WHERE x.rn = 1
  ),

  rec_i18n AS (
    SELECT
      entity_id AS record_id,
      max(text_value) FILTER (WHERE lang='en' AND field_name='title')        AS title_en,
      max(text_value) FILTER (WHERE lang='en' AND field_name='description')  AS description_en,
      string_agg(text_value, E'\n' ORDER BY field_name)
        FILTER (WHERE lang='en' AND field_name NOT IN ('title','description')) AS other_en,

      max(text_value) FILTER (WHERE lang='it' AND field_name='title')        AS title_it,
      max(text_value) FILTER (WHERE lang='it' AND field_name='description')  AS description_it,
      string_agg(text_value, E'\n' ORDER BY field_name)
        FILTER (WHERE lang='it' AND field_name NOT IN ('title','description')) AS other_it
    FROM best_i18n
    WHERE entity_type='record'
    GROUP BY entity_id
  ),

  agent_labels AS (
    SELECT
      entity_id AS agent_id,
      lang,
      COALESCE(
        max(text_value) FILTER (WHERE field_name='pref_label'),
        max(text_value) FILTER (WHERE field_name='name'),
        max(text_value) FILTER (WHERE field_name='label')
      ) AS label
    FROM best_i18n
    WHERE entity_type='agent'
    GROUP BY entity_id, lang
  ),
  concept_labels AS (
    SELECT
      entity_id AS concept_id,
      lang,
      COALESCE(
        max(text_value) FILTER (WHERE field_name='pref_label'),
        max(text_value) FILTER (WHERE field_name='name'),
        max(text_value) FILTER (WHERE field_name='label')
      ) AS label
    FROM best_i18n
    WHERE entity_type='concept'
    GROUP BY entity_id, lang
  ),
  place_labels AS (
    SELECT
      entity_id AS place_id,
      lang,
      COALESCE(
        max(text_value) FILTER (WHERE field_name='pref_label'),
        max(text_value) FILTER (WHERE field_name='name'),
        max(text_value) FILTER (WHERE field_name='label')
      ) AS label
    FROM best_i18n
    WHERE entity_type='place'
    GROUP BY entity_id, lang
  ),

  rec_agents AS (
    SELECT
      ra.record_id,
      al.lang,
      string_agg(al.label, '; ' ORDER BY ra.ord) AS agents
    FROM iartnet_master.record_agents ra
    JOIN agent_labels al ON al.agent_id = ra.agent_id
    WHERE al.label IS NOT NULL AND length(al.label) > 0
      AND al.lang IN ('en','it')
    GROUP BY ra.record_id, al.lang
  ),
  rec_concepts AS (
    SELECT
      rc.record_id,
      cl.lang,
      string_agg(cl.label, '; ') AS concepts
    FROM iartnet_master.record_concepts rc
    JOIN concept_labels cl ON cl.concept_id = rc.concept_id
    WHERE cl.label IS NOT NULL AND length(cl.label) > 0
      AND cl.lang IN ('en','it')
    GROUP BY rc.record_id, cl.lang
  ),
  rec_places AS (
    SELECT
      rp.record_id,
      pl.lang,
      string_agg(pl.label, '; ') AS places
    FROM iartnet_master.record_places rp
    JOIN place_labels pl ON pl.place_id = rp.place_id
    WHERE pl.label IS NOT NULL AND length(pl.label) > 0
      AND pl.lang IN ('en','it')
    GROUP BY rp.record_id, pl.lang
  ),

  src AS (
    SELECT
      r.id AS record_id,
      r.stable_id,
      r.primary_institution_id,
      r.publish_state,

      ri.title_en, ri.description_en, ri.other_en,
      ra_en.agents   AS agents_en,
      rc_en.concepts AS concepts_en,
      rp_en.places   AS places_en,

      ri.title_it, ri.description_it, ri.other_it,
      ra_it.agents   AS agents_it,
      rc_it.concepts AS concepts_it,
      rp_it.places   AS places_it

    FROM iartnet_master.records r
    LEFT JOIN rec_i18n ri ON ri.record_id = r.id

    LEFT JOIN rec_agents   ra_en ON ra_en.record_id = r.id AND ra_en.lang='en'
    LEFT JOIN rec_concepts rc_en ON rc_en.record_id = r.id AND rc_en.lang='en'
    LEFT JOIN rec_places   rp_en ON rp_en.record_id = r.id AND rp_en.lang='en'

    LEFT JOIN rec_agents   ra_it ON ra_it.record_id = r.id AND ra_it.lang='it'
    LEFT JOIN rec_concepts rc_it ON rc_it.record_id = r.id AND rc_it.lang='it'
    LEFT JOIN rec_places   rp_it ON rp_it.record_id = r.id AND rp_it.lang='it'
  )

  INSERT INTO iartnet_master.record_search_en (
    record_id, stable_id, primary_institution_id, publish_state,
    title_en, description_en, content_en, tsv_en, lexemes_en, has_en,
    title_it, description_it, content_it, tsv_it, lexemes_it, has_it,
    updated_at
  )
  SELECT
    s.record_id, s.stable_id, s.primary_institution_id, s.publish_state,

    s.title_en,
    s.description_en,
    COALESCE(trim(regexp_replace(
      concat_ws(' ', s.title_en, s.description_en, s.other_en, s.agents_en, s.concepts_en, s.places_en),
      '\s+',' ','g'
    )), '') AS content_en,

    (
      setweight(to_tsvector('english', public.unaccent(coalesce(s.title_en,''))), 'A') ||
      setweight(to_tsvector('english', public.unaccent(coalesce(s.description_en,''))), 'B') ||
      setweight(to_tsvector('english', public.unaccent(coalesce(concat_ws(' ', s.other_en, s.agents_en, s.concepts_en, s.places_en),''))), 'C')
    ) AS tsv_en,

    tsvector_to_array(
      setweight(to_tsvector('english', public.unaccent(coalesce(s.title_en,''))), 'A') ||
      setweight(to_tsvector('english', public.unaccent(coalesce(s.description_en,''))), 'B') ||
      setweight(to_tsvector('english', public.unaccent(coalesce(concat_ws(' ', s.other_en, s.agents_en, s.concepts_en, s.places_en),''))), 'C')
    ) AS lexemes_en,

    (coalesce(length(trim(regexp_replace(
      concat_ws(' ', s.title_en, s.description_en, s.other_en, s.agents_en, s.concepts_en, s.places_en),
      '\s+',' ','g'
    ))),0) > 0) AS has_en,

    s.title_it,
    s.description_it,
    COALESCE(trim(regexp_replace(
      concat_ws(' ', s.title_it, s.description_it, s.other_it, s.agents_it, s.concepts_it, s.places_it),
      '\s+',' ','g'
    )), '') AS content_it,

    (
      setweight(to_tsvector('italian', public.unaccent(coalesce(s.title_it,''))), 'A') ||
      setweight(to_tsvector('italian', public.unaccent(coalesce(s.description_it,''))), 'B') ||
      setweight(to_tsvector('italian', public.unaccent(coalesce(concat_ws(' ', s.other_it, s.agents_it, s.concepts_it, s.places_it),''))), 'C')
    ) AS tsv_it,

    tsvector_to_array(
      setweight(to_tsvector('italian', public.unaccent(coalesce(s.title_it,''))), 'A') ||
      setweight(to_tsvector('italian', public.unaccent(coalesce(s.description_it,''))), 'B') ||
      setweight(to_tsvector('italian', public.unaccent(coalesce(concat_ws(' ', s.other_it, s.agents_it, s.concepts_it, s.places_it),''))), 'C')
    ) AS lexemes_it,

    (coalesce(length(trim(regexp_replace(
      concat_ws(' ', s.title_it, s.description_it, s.other_it, s.agents_it, s.concepts_it, s.places_it),
      '\s+',' ','g'
    ))),0) > 0) AS has_it,

    now()
  FROM src s;

  GET DIAGNOSTICS v_cnt = ROW_COUNT;
  RETURN v_cnt;
END;
$$;

-- --------------------------------------------------------------------
-- 5) Fuzzy score (language-agnostic): token>=5, maxdist=ceil(len*0.10), same prefix3
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.fn_fuzzy_score_en(
  p_lexemes text[],
  p_tokens  text[]
) RETURNS double precision
LANGUAGE sql
STABLE
SET search_path = iartnet_master, public
AS $$
  WITH toks AS (
    SELECT
      t AS token,
      left(t,3) AS prefix3,
      length(t) AS len,
      ceil(length(t) * 0.10)::int AS maxdist
    FROM unnest(p_tokens) t
    WHERE length(t) >= 5
  ),
  per_tok AS (
    SELECT
      token, len, maxdist, prefix3,
      (
        SELECT COALESCE(MAX(1.0 - (d::double precision / len::double precision)), 0.0)
        FROM (
          SELECT public.levenshtein_less_equal(lex, token, maxdist) AS d
          FROM unnest(coalesce(p_lexemes, ARRAY[]::text[])) lex
          WHERE left(lex,3) = prefix3
            AND abs(length(lex) - len) <= maxdist
        ) x
        WHERE x.d <= maxdist
      ) AS score
    FROM toks
  )
  SELECT COALESCE(avg(score), 0.0) FROM per_tok;
$$;

-- --------------------------------------------------------------------
-- 6) Search API: ONLY published + EN primary, fallback IT if EN missing
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.search_records_en(
  p_query  text,
  p_limit  int DEFAULT 20,
  p_offset int DEFAULT 0,
  p_mode   text DEFAULT 'AND'
) RETURNS TABLE (
  record_id uuid,
  stable_id text,
  title_en  text,
  snippet   text,
  score_total double precision,
  score_fts   double precision,
  score_fuzzy double precision,
  used_lang text
)
LANGUAGE plpgsql
STABLE
SET search_path = iartnet_master, public
AS $$
DECLARE
  v_norm text;
  v_tokens text[];
  v_sep text;

  v_prefixes_en text[];
  v_prefixes_it text[];

  v_tsquery_text_en text;
  v_tsquery_text_it text;

  v_tsq_en tsquery;
  v_tsq_it tsquery;

  v_candidate_limit int;
BEGIN
  v_norm := lower(public.unaccent(coalesce(p_query,'')));

  SELECT array_agg(m[1]) INTO v_tokens
  FROM regexp_matches(v_norm, '[a-z0-9]{3,}', 'g') m;

  IF v_tokens IS NULL OR array_length(v_tokens,1) IS NULL THEN
    RETURN;
  END IF;

  v_sep := CASE WHEN upper(coalesce(p_mode,'AND')) = 'OR' THEN ' | ' ELSE ' & ' END;

  SELECT array_agg(left(COALESCE((tsvector_to_array(to_tsvector('english', tok)))[1], tok),3))
  INTO v_prefixes_en
  FROM unnest(v_tokens) tok;

  SELECT array_agg(left(COALESCE((tsvector_to_array(to_tsvector('italian', tok)))[1], tok),3))
  INTO v_prefixes_it
  FROM unnest(v_tokens) tok;

  SELECT array_agg(p) INTO v_prefixes_en FROM unnest(v_prefixes_en) p WHERE length(p)=3;
  SELECT array_agg(p) INTO v_prefixes_it FROM unnest(v_prefixes_it) p WHERE length(p)=3;

  IF v_prefixes_en IS NULL OR array_length(v_prefixes_en,1) IS NULL THEN
    RETURN;
  END IF;

  SELECT string_agg(pfx || ':*', v_sep) INTO v_tsquery_text_en
  FROM unnest(v_prefixes_en) pfx;

  SELECT string_agg(pfx || ':*', v_sep) INTO v_tsquery_text_it
  FROM unnest(coalesce(v_prefixes_it, v_prefixes_en)) pfx;

  v_tsq_en := to_tsquery('english', v_tsquery_text_en);
  v_tsq_it := to_tsquery('italian', v_tsquery_text_it);

  v_candidate_limit := LEAST(2000, GREATEST(200, (GREATEST(p_limit,1) + GREATEST(p_offset,0)) * 50));

  RETURN QUERY
  WITH candidates AS (
    SELECT
      rs.record_id,
      rs.stable_id,
      rs.title_en,
      rs.title_it,
      rs.content_en,
      rs.content_it,
      rs.lexemes_en,
      rs.lexemes_it,
      rs.has_en,
      rs.has_it,
      CASE
        WHEN rs.has_en THEN ts_rank_cd(rs.tsv_en, v_tsq_en, 32)
        ELSE ts_rank_cd(rs.tsv_it, v_tsq_it, 32)
      END AS rank_fts
    FROM iartnet_master.record_search_en rs
    WHERE rs.publish_state = 'published'
      AND (
        (rs.has_en AND rs.tsv_en @@ v_tsq_en)
        OR
        ((NOT rs.has_en) AND rs.has_it AND rs.tsv_it @@ v_tsq_it)
      )
    ORDER BY rank_fts DESC
    LIMIT v_candidate_limit
  ),
  scored AS (
    SELECT
      c.*,
      iartnet_master.fn_fuzzy_score_en(
        CASE WHEN c.has_en THEN c.lexemes_en ELSE c.lexemes_it END,
        v_tokens
      ) AS rank_fuzzy
    FROM candidates c
  )
  SELECT
    s.record_id,
    s.stable_id,
    COALESCE(s.title_en, s.title_it) AS title_en,
    CASE
      WHEN s.has_en THEN ts_headline(
        'english', s.content_en, v_tsq_en,
        'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … '
      )
      ELSE ts_headline(
        'italian', s.content_it, v_tsq_it,
        'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … '
      )
    END AS snippet,
    (0.75 * s.rank_fts + 0.25 * s.rank_fuzzy) AS score_total,
    s.rank_fts::double precision AS score_fts,
    s.rank_fuzzy::double precision AS score_fuzzy,
    CASE WHEN s.has_en THEN 'en' ELSE 'it' END AS used_lang
  FROM scored s
  ORDER BY score_total DESC, score_fts DESC, stable_id
  OFFSET GREATEST(p_offset,0)
  LIMIT GREATEST(p_limit,1);

END;
$$;

-- --------------------------------------------------------------------
-- 7) Triggers: incremental maintenance
-- --------------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.trg_records_search_en()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    DELETE FROM iartnet_master.record_search_en WHERE record_id = OLD.id;
    RETURN NULL;
  END IF;

  PERFORM iartnet_master.record_search_en_build(NEW.id);
  RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION iartnet_master.trg_record_rel_touch_search_en()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
DECLARE v_record_id uuid;
BEGIN
  v_record_id := COALESCE(NEW.record_id, OLD.record_id);
  IF v_record_id IS NOT NULL THEN
    PERFORM iartnet_master.record_search_en_build(v_record_id);
  END IF;
  RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION iartnet_master.trg_i18n_texts_search_en()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
DECLARE
  v_entity_type text;
  v_entity_id uuid;
  v_lang text;
  v_rec uuid;
BEGIN
  v_entity_type := COALESCE(NEW.entity_type, OLD.entity_type);
  v_entity_id   := COALESCE(NEW.entity_id,   OLD.entity_id);
  v_lang        := COALESCE(NEW.lang,        OLD.lang);

  IF v_lang NOT IN ('en','it') THEN
    RETURN NULL;
  END IF;

  IF v_entity_type = 'record' THEN
    PERFORM iartnet_master.record_search_en_build(v_entity_id);

  ELSIF v_entity_type = 'agent' THEN
    FOR v_rec IN SELECT ra.record_id FROM iartnet_master.record_agents ra WHERE ra.agent_id = v_entity_id
    LOOP
      PERFORM iartnet_master.record_search_en_build(v_rec);
    END LOOP;

  ELSIF v_entity_type = 'concept' THEN
    FOR v_rec IN SELECT rc.record_id FROM iartnet_master.record_concepts rc WHERE rc.concept_id = v_entity_id
    LOOP
      PERFORM iartnet_master.record_search_en_build(v_rec);
    END LOOP;

  ELSIF v_entity_type = 'place' THEN
    FOR v_rec IN SELECT rp.record_id FROM iartnet_master.record_places rp WHERE rp.place_id = v_entity_id
    LOOP
      PERFORM iartnet_master.record_search_en_build(v_rec);
    END LOOP;
  END IF;

  RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS trg_records_search_en ON iartnet_master.records;
CREATE TRIGGER trg_records_search_en
AFTER INSERT OR UPDATE OR DELETE ON iartnet_master.records
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_records_search_en();

DROP TRIGGER IF EXISTS trg_record_agents_search_en ON iartnet_master.record_agents;
CREATE TRIGGER trg_record_agents_search_en
AFTER INSERT OR UPDATE OR DELETE ON iartnet_master.record_agents
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_record_rel_touch_search_en();

DROP TRIGGER IF EXISTS trg_record_concepts_search_en ON iartnet_master.record_concepts;
CREATE TRIGGER trg_record_concepts_search_en
AFTER INSERT OR UPDATE OR DELETE ON iartnet_master.record_concepts
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_record_rel_touch_search_en();

DROP TRIGGER IF EXISTS trg_record_places_search_en ON iartnet_master.record_places;
CREATE TRIGGER trg_record_places_search_en
AFTER INSERT OR UPDATE OR DELETE ON iartnet_master.record_places
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_record_rel_touch_search_en();

DROP TRIGGER IF EXISTS trg_i18n_texts_search_en ON iartnet_master.i18n_texts;
CREATE TRIGGER trg_i18n_texts_search_en
AFTER INSERT OR UPDATE OR DELETE ON iartnet_master.i18n_texts
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_i18n_texts_search_en();

COMMIT;
