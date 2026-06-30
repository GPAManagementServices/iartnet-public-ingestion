BEGIN;

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
      t.term       AS term,
      t.lang       AS lang,
      t.freq       AS freq,
      t.term_norm  AS term_norm,
      (t.term_norm LIKE v_tail || '%') AS is_prefix,
      public.similarity(t.term_norm, v_tail) AS sim
    FROM iartnet_master.search_suggest_terms t
    JOIN langs l ON l.lang = t.lang
    WHERE
      (t.term_norm LIKE v_tail || '%')
      OR (length(v_tail) >= 4 AND t.term_norm % v_tail)
  ),
  scored AS (
    SELECT
      b.term AS term,
      b.lang AS lang,
      b.freq AS freq,
      (
        ((CASE WHEN b.is_prefix THEN 1.0 ELSE 0.0 END) * 0.75 + b.sim * 0.25)
        * (1.0 + ln(b.freq + 1) / 10.0)
      ) AS score
    FROM base b
  )
  SELECT s.term, s.lang, s.freq, s.score
  FROM scored s
  ORDER BY s.score DESC, s.freq DESC, s.term
  LIMIT GREATEST(p_limit,1);

END;
$$;

COMMIT;
