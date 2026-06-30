BEGIN;

-- Open Source: trigram extension
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;

-- ------------------------------------------------------------
-- 1) Dizionario termini per suggerimenti (EN + IT, published only)
-- term_norm = già normalizzato (lower+unaccent), quindi indicizzabile
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS iartnet_master.search_suggest_terms (
  lang text NOT NULL CHECK (lang IN ('en','it')),
  term text NOT NULL,         -- display (qui: uguale a term_norm, lowercase)
  term_norm text NOT NULL,    -- chiave per matching (lower+unaccent)
  freq bigint NOT NULL,
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (lang, term_norm)
);

-- Prefix (LIKE 'abc%') veloce
CREATE INDEX IF NOT EXISTS search_suggest_terms_prefix_idx
  ON iartnet_master.search_suggest_terms (lang, term_norm text_pattern_ops);

-- Fuzzy / similarity veloce
CREATE INDEX IF NOT EXISTS search_suggest_terms_trgm_gin
  ON iartnet_master.search_suggest_terms USING gin (term_norm gin_trgm_ops);

-- Top terms per lingua (quando vuoi mostrare “trend”)
CREATE INDEX IF NOT EXISTS search_suggest_terms_top_idx
  ON iartnet_master.search_suggest_terms (lang, freq DESC);

-- ------------------------------------------------------------
-- 2) Stoplist (facoltativa ma consigliata per evitare rumore)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS iartnet_master.search_suggest_stoplist (
  term_norm text PRIMARY KEY
);

-- Seed minimo (aggiungi roba “sporca” tipica IARTNET)
INSERT INTO iartnet_master.search_suggest_stoplist(term_norm) VALUES
  ('region'), ('regione'), ('code'), ('codice'), ('sirbec'), ('idk'),
  ('purchase'), ('card'), ('containing'), ('freely'), ('accessible'),
  ('lombardy'), ('lombardia')
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------
-- 3) Rebuild del dizionario da record_search_en (published-only)
--    Estrae token da content_en/content_it, filtra stopword e stoplist.
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.search_suggest_rebuild_all()
RETURNS bigint
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
DECLARE v_cnt bigint;
BEGIN
  TRUNCATE TABLE iartnet_master.search_suggest_terms;

  WITH tokens AS (
    -- EN tokens
    SELECT 'en'::text AS lang, m[1] AS token
    FROM iartnet_master.record_search_en rs
    CROSS JOIN LATERAL regexp_matches(public.unaccent(lower(coalesce(rs.content_en,''))), '[a-z]{3,}', 'g') m
    WHERE rs.publish_state='published' AND rs.has_en

    UNION ALL

    -- IT tokens
    SELECT 'it'::text AS lang, m[1] AS token
    FROM iartnet_master.record_search_en rs
    CROSS JOIN LATERAL regexp_matches(public.unaccent(lower(coalesce(rs.content_it,''))), '[a-z]{3,}', 'g') m
    WHERE rs.publish_state='published' AND rs.has_it
  ),
  cleaned AS (
    SELECT
      lang,
      token AS term_norm
    FROM tokens
    WHERE length(token) BETWEEN 3 AND 40
      AND token NOT IN (SELECT term_norm FROM iartnet_master.search_suggest_stoplist)
      AND (
        (lang='en' AND to_tsvector('english', token) <> ''::tsvector) OR
        (lang='it' AND to_tsvector('italian', token) <> ''::tsvector)
      )
  )
  INSERT INTO iartnet_master.search_suggest_terms(lang, term, term_norm, freq, updated_at)
  SELECT
    lang,
    term_norm AS term,
    term_norm,
    count(*)::bigint AS freq,
    now()
  FROM cleaned
  GROUP BY lang, term_norm;

  GET DIAGNOSTICS v_cnt = ROW_COUNT;
  RETURN v_cnt;
END;
$$;

-- ------------------------------------------------------------
-- 4) Endpoint: suggerisce parole mentre l'utente digita
--    - prende l’ULTIMO token (quello incompleto) dalla query
--    - fa prima prefix match, poi fuzzy (trgm) se serve
--    - MIXED = EN + IT
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION iartnet_master.search_suggest_terms(
  p_input text,
  p_limit int DEFAULT 10,
  p_lang_mode text DEFAULT 'MIXED'
) RETURNS TABLE (
  term text,
  lang text,
  freq bigint,
  score double precision
)
LANGUAGE plpgsql
STABLE
SET search_path = iartnet_master, public
AS $$
DECLARE
  v_norm text;
  v_tail text;
  v_mode text;
BEGIN
  v_mode := upper(coalesce(p_lang_mode,'MIXED'));
  IF v_mode NOT IN ('EN','IT','MIXED') THEN
    v_mode := 'MIXED';
  END IF;

  v_norm := public.unaccent(lower(coalesce(p_input,'')));

  -- ultimo token digitato (se l’utente ha scritto più parole)
  SELECT (regexp_matches(v_norm, '([a-z0-9]+)$'))[1] INTO v_tail;

  IF v_tail IS NULL OR length(v_tail) < 2 THEN
    RETURN;
  END IF;

  -- threshold fuzzy ragionevole per suggerimenti
  PERFORM set_config('pg_trgm.similarity_threshold','0.25', true);

  RETURN QUERY
  WITH langs AS (
    SELECT unnest(
      CASE v_mode
        WHEN 'EN' THEN ARRAY['en']::text[]
        WHEN 'IT' THEN ARRAY['it']::text[]
        ELSE ARRAY['en','it']::text[]
      END
    ) AS lang
  ),
  base AS (
    SELECT
      t.term, t.lang, t.freq, t.term_norm,
      (t.term_norm LIKE v_tail || '%') AS is_prefix,
      public.similarity(t.term_norm, v_tail) AS sim
    FROM iartnet_master.search_suggest_terms t
    JOIN langs l ON l.lang = t.lang
    WHERE
      -- prefix sempre
      (t.term_norm LIKE v_tail || '%')
      -- fuzzy solo se il token è “abbastanza lungo”
      OR (length(v_tail) >= 4 AND t.term_norm % v_tail)
  ),
  scored AS (
    SELECT
      term, lang, freq,
      -- score “google-like”: prefix > similarity, e freq come boost logaritmico
      (
        (CASE WHEN is_prefix THEN 1.0 ELSE 0.0 END) * 0.75
        + sim * 0.25
      )
      * (1.0 + ln(freq + 1) / 10.0) AS score
    FROM base
  )
  SELECT term, lang, freq, score
  FROM scored
  ORDER BY score DESC, freq DESC, term
  LIMIT GREATEST(p_limit,1);

END;
$$;

COMMIT;
