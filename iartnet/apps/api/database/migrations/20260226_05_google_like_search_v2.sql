BEGIN;

-- 0) pg_trgm (open source)
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;

-- 1) Colonne normalizzate (materializzate) per indicizzazione trigram
ALTER TABLE iartnet_master.record_search_en
  ADD COLUMN IF NOT EXISTS title_en_norm text,
  ADD COLUMN IF NOT EXISTS title_it_norm text;

-- Trigger: mantiene sempre aggiornati i campi *_norm (così evitiamo unaccent() nell'indice)
CREATE OR REPLACE FUNCTION iartnet_master.trg_record_search_norm_titles()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = iartnet_master, public
AS $$
BEGIN
  NEW.title_en_norm := CASE
    WHEN NEW.title_en IS NULL OR length(NEW.title_en)=0 THEN NULL
    ELSE public.unaccent(lower(NEW.title_en))
  END;

  NEW.title_it_norm := CASE
    WHEN NEW.title_it IS NULL OR length(NEW.title_it)=0 THEN NULL
    ELSE public.unaccent(lower(NEW.title_it))
  END;

  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_record_search_norm_titles ON iartnet_master.record_search_en;
CREATE TRIGGER trg_record_search_norm_titles
BEFORE INSERT OR UPDATE ON iartnet_master.record_search_en
FOR EACH ROW EXECUTE FUNCTION iartnet_master.trg_record_search_norm_titles();

-- Backfill (fa scattare il trigger e popola *_norm sulle righe esistenti)
UPDATE iartnet_master.record_search_en
SET title_en = title_en
WHERE title_en IS NOT NULL;

UPDATE iartnet_master.record_search_en
SET title_it = title_it
WHERE title_it IS NOT NULL;

-- 2) Indici trigram su colonne normalizzate (published-only)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname='iartnet_master' AND indexname='record_search_title_en_norm_trgm_pub_gin'
  ) THEN
    EXECUTE $q$
      CREATE INDEX record_search_title_en_norm_trgm_pub_gin
      ON iartnet_master.record_search_en USING gin (title_en_norm gin_trgm_ops)
      WHERE publish_state='published' AND has_en AND title_en_norm IS NOT NULL
    $q$;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname='iartnet_master' AND indexname='record_search_title_it_norm_trgm_pub_gin'
  ) THEN
    EXECUTE $q$
      CREATE INDEX record_search_title_it_norm_trgm_pub_gin
      ON iartnet_master.record_search_en USING gin (title_it_norm gin_trgm_ops)
      WHERE publish_state='published' AND has_it AND title_it_norm IS NOT NULL
    $q$;
  END IF;
END $$;

-- 3) FUZZY "google-like": permissivo su lunghi, conservativo su corti + cap dinamico
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
      LEAST(
        CASE
          WHEN length(t) >= 10 THEN ceil(length(t) * 0.30)::int
          WHEN length(t) >= 8  THEN ceil(length(t) * 0.25)::int
          WHEN length(t) >= 6  THEN ceil(length(t) * 0.20)::int
          ELSE                   ceil(length(t) * 0.10)::int
        END,
        CASE
          WHEN length(t) < 10 THEN 3
          WHEN length(t) < 14 THEN 4
          ELSE 6
        END
      ) AS maxdist
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

-- 4) SEARCH "google-like" (MIXED + ranking robusto + fallback trigram se FTS=0)
DROP FUNCTION IF EXISTS iartnet_master.search_records_en(text,int,int,text);

CREATE OR REPLACE FUNCTION iartnet_master.search_records_en(
  p_query     text,
  p_limit     int  DEFAULT 20,
  p_offset    int  DEFAULT 0,
  p_mode      text DEFAULT 'AND',
  p_lang_mode text DEFAULT 'FALLBACK'
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
  v_lang_mode text;
  v_has_fts boolean;
BEGIN
  v_lang_mode := upper(coalesce(p_lang_mode,'FALLBACK'));
  IF v_lang_mode NOT IN ('FALLBACK','MIXED','AUTO') THEN
    v_lang_mode := 'FALLBACK';
  END IF;
  IF v_lang_mode = 'AUTO' THEN
    v_lang_mode := 'MIXED';
  END IF;

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

  IF v_lang_mode = 'MIXED' THEN
    -- check rapido: esiste almeno 1 match FTS? (molto più economico di count(*))
    SELECT TRUE INTO v_has_fts
    FROM iartnet_master.record_search_en rs
    WHERE rs.publish_state='published'
      AND (
        (rs.has_en AND rs.tsv_en @@ v_tsq_en)
        OR
        (rs.has_it AND rs.tsv_it @@ v_tsq_it)
      )
    LIMIT 1;

    IF NOT FOUND THEN
      -- FTS=0: fallback trigram (did-you-mean style)
      PERFORM set_config('pg_trgm.similarity_threshold','0.25', true);

      RETURN QUERY
      WITH tri AS (
        SELECT
          rs.record_id, rs.stable_id,
          rs.title_en, rs.title_it,
          rs.title_en_norm, rs.title_it_norm,
          rs.content_en, rs.content_it,
          rs.lexemes_en, rs.lexemes_it,
          rs.has_en, rs.has_it,
          CASE WHEN rs.has_en AND rs.title_en_norm IS NOT NULL THEN public.similarity(rs.title_en_norm, v_norm) ELSE 0 END AS sim_en,
          CASE WHEN rs.has_it AND rs.title_it_norm IS NOT NULL THEN public.similarity(rs.title_it_norm, v_norm) ELSE 0 END AS sim_it
        FROM iartnet_master.record_search_en rs
        WHERE rs.publish_state='published'
          AND (
            (rs.has_en AND rs.title_en_norm IS NOT NULL AND rs.title_en_norm % v_norm)
            OR
            (rs.has_it AND rs.title_it_norm IS NOT NULL AND rs.title_it_norm % v_norm)
          )
        ORDER BY GREATEST(
          CASE WHEN rs.has_en AND rs.title_en_norm IS NOT NULL THEN public.similarity(rs.title_en_norm, v_norm) ELSE 0 END,
          CASE WHEN rs.has_it AND rs.title_it_norm IS NOT NULL THEN public.similarity(rs.title_it_norm, v_norm) ELSE 0 END
        ) DESC
        LIMIT v_candidate_limit
      ),
      scored AS (
        SELECT
          t.*,
          CASE WHEN t.sim_en >= t.sim_it THEN 'en' ELSE 'it' END AS chosen_lang,
          GREATEST(t.sim_en, t.sim_it) AS rank_trgm,
          0.0::double precision AS rank_fts,
          CASE
            WHEN t.sim_en >= t.sim_it THEN iartnet_master.fn_fuzzy_score_en(t.lexemes_en, v_tokens)
            ELSE iartnet_master.fn_fuzzy_score_en(t.lexemes_it, v_tokens)
          END AS rank_fuzzy
        FROM tri t
      )
      SELECT
        s.record_id,
        s.stable_id,
        COALESCE(s.title_en, s.title_it) AS title_en,
        CASE
          WHEN s.chosen_lang='en' THEN ts_headline('english', s.content_en, v_tsq_en,
            'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
          ELSE ts_headline('italian', s.content_it, v_tsq_it,
            'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
        END AS snippet,
        (0.85 * s.rank_trgm + 0.15 * s.rank_fuzzy) AS score_total,
        s.rank_fts AS score_fts,
        s.rank_fuzzy AS score_fuzzy,
        s.chosen_lang AS used_lang
      FROM scored s
      ORDER BY score_total DESC, stable_id
      OFFSET GREATEST(p_offset,0)
      LIMIT GREATEST(p_limit,1);

      RETURN;
    END IF;

    -- Path normale: FTS + segnali (TRGM titolo + fuzzy)
    RETURN QUERY
    WITH candidates AS (
      SELECT
        rs.record_id, rs.stable_id,
        rs.title_en, rs.title_it,
        rs.title_en_norm, rs.title_it_norm,
        rs.content_en, rs.content_it,
        rs.lexemes_en, rs.lexemes_it,
        rs.has_en, rs.has_it,
        CASE WHEN rs.has_en THEN ts_rank_cd(rs.tsv_en, v_tsq_en, 32) ELSE 0 END AS rank_en,
        CASE WHEN rs.has_it THEN ts_rank_cd(rs.tsv_it, v_tsq_it, 32) ELSE 0 END AS rank_it
      FROM iartnet_master.record_search_en rs
      WHERE rs.publish_state='published'
        AND (
          (rs.has_en AND rs.tsv_en @@ v_tsq_en)
          OR
          (rs.has_it AND rs.tsv_it @@ v_tsq_it)
        )
      ORDER BY GREATEST(
        CASE WHEN rs.has_en THEN ts_rank_cd(rs.tsv_en, v_tsq_en, 32) ELSE 0 END,
        CASE WHEN rs.has_it THEN ts_rank_cd(rs.tsv_it, v_tsq_it, 32) ELSE 0 END
      ) DESC
      LIMIT v_candidate_limit
    ),
    scored AS (
      SELECT
        c.*,
        CASE WHEN c.rank_en >= c.rank_it THEN 'en' ELSE 'it' END AS chosen_lang,
        GREATEST(c.rank_en, c.rank_it) AS rank_fts,
        CASE
          WHEN c.rank_en >= c.rank_it THEN COALESCE(public.word_similarity(v_norm, c.title_en_norm),0)
          ELSE COALESCE(public.word_similarity(v_norm, c.title_it_norm),0)
        END AS rank_trgm,
        CASE
          WHEN c.rank_en >= c.rank_it THEN iartnet_master.fn_fuzzy_score_en(c.lexemes_en, v_tokens)
          ELSE iartnet_master.fn_fuzzy_score_en(c.lexemes_it, v_tokens)
        END AS rank_fuzzy_raw
      FROM candidates c
    ),
    final AS (
      SELECT
        s.*,
        (CASE WHEN s.rank_fts < 0.05 THEN s.rank_fuzzy_raw * 0.60 ELSE s.rank_fuzzy_raw END) AS rank_fuzzy
      FROM scored s
    )
    SELECT
      f.record_id,
      f.stable_id,
      COALESCE(f.title_en, f.title_it) AS title_en,
      CASE
        WHEN f.chosen_lang='en' THEN ts_headline('english', f.content_en, v_tsq_en,
          'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
        ELSE ts_headline('italian', f.content_it, v_tsq_it,
          'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
      END AS snippet,
      (0.65 * f.rank_fts + 0.20 * f.rank_trgm + 0.15 * f.rank_fuzzy) AS score_total,
      f.rank_fts::double precision AS score_fts,
      f.rank_fuzzy::double precision AS score_fuzzy,
      f.chosen_lang AS used_lang
    FROM final f
    ORDER BY score_total DESC, score_fts DESC, stable_id
    OFFSET GREATEST(p_offset,0)
    LIMIT GREATEST(p_limit,1);

  ELSE
    -- FALLBACK: EN se esiste, altrimenti IT (manteniamo compat)
    RETURN QUERY
    WITH candidates AS (
      SELECT
        rs.record_id, rs.stable_id,
        rs.title_en, rs.title_it,
        rs.title_en_norm, rs.title_it_norm,
        rs.content_en, rs.content_it,
        rs.lexemes_en, rs.lexemes_it,
        rs.has_en, rs.has_it,
        CASE
          WHEN rs.has_en THEN ts_rank_cd(rs.tsv_en, v_tsq_en, 32)
          ELSE ts_rank_cd(rs.tsv_it, v_tsq_it, 32)
        END AS rank_fts,
        CASE WHEN rs.has_en THEN 'en' ELSE 'it' END AS chosen_lang
      FROM iartnet_master.record_search_en rs
      WHERE rs.publish_state='published'
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
        CASE
          WHEN c.chosen_lang='en' THEN COALESCE(public.word_similarity(v_norm, c.title_en_norm),0)
          ELSE COALESCE(public.word_similarity(v_norm, c.title_it_norm),0)
        END AS rank_trgm,
        CASE
          WHEN c.chosen_lang='en' THEN iartnet_master.fn_fuzzy_score_en(c.lexemes_en, v_tokens)
          ELSE iartnet_master.fn_fuzzy_score_en(c.lexemes_it, v_tokens)
        END AS rank_fuzzy_raw
      FROM candidates c
    ),
    final AS (
      SELECT
        s.*,
        (CASE WHEN s.rank_fts < 0.05 THEN s.rank_fuzzy_raw * 0.60 ELSE s.rank_fuzzy_raw END) AS rank_fuzzy
      FROM scored s
    )
    SELECT
      f.record_id,
      f.stable_id,
      COALESCE(f.title_en, f.title_it) AS title_en,
      CASE
        WHEN f.chosen_lang='en' THEN ts_headline('english', f.content_en, v_tsq_en,
          'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
        ELSE ts_headline('italian', f.content_it, v_tsq_it,
          'StartSel=<mark>,StopSel=</mark>,MaxFragments=2,MinWords=8,MaxWords=28,ShortWord=3,FragmentDelimiter= … ')
      END AS snippet,
      (0.70 * f.rank_fts + 0.20 * f.rank_trgm + 0.10 * f.rank_fuzzy) AS score_total,
      f.rank_fts::double precision AS score_fts,
      f.rank_fuzzy::double precision AS score_fuzzy,
      f.chosen_lang AS used_lang
    FROM final f
    ORDER BY score_total DESC, score_fts DESC, stable_id
    OFFSET GREATEST(p_offset,0)
    LIMIT GREATEST(p_limit,1);
  END IF;

END;
$$;

-- 5) Endpoint DB "public": forza MIXED
CREATE OR REPLACE FUNCTION iartnet_master.search_public(
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
LANGUAGE sql
STABLE
SET search_path = iartnet_master, public
AS $$
  SELECT *
  FROM iartnet_master.search_records_en(p_query, p_limit, p_offset, p_mode, 'MIXED');
$$;

COMMIT;
