# IARTNET – Setup ricerca "Google-like" + Autocomplete (PG18)

Questa guida applica le migrazioni SQL che abilitano:
- ricerca pubblica "google-like" (FTS + trigram + fuzzy, **published-only**)
- suggerimenti (autocomplete) termini mentre l’utente digita

## File coinvolti (ordine di applicazione)
1. `20260226_00_fts_published_fallback_it_FULL.sql`
2. `20260226_05_google_like_search_v2.sql`
3. `20260226_06_search_autocomplete.sql`
4. `20260226_06b_fix_autocomplete_terms.sql`

> Nota: `06b` corregge l’ambiguità PL/pgSQL (RETURNS TABLE vs colonne) nella funzione `search_suggest_terms`.
> Per ora applicala sempre dopo `06`.

---

# A) Setup su Ubuntu con PostgreSQL in Docker Compose

## A.1 Applica migrazioni SQL (ordine corretto)
Assumiamo:
- repo: `/opt/Ingestion/iartnet`
- compose dir: `/opt/Ingestion/iartnet/infra/docker/api/compose`
- env: `/opt/Ingestion/iartnet/apps/api/.env`
- migrations: `/opt/Ingestion/iartnet/apps/api/database/migrations`

```bash
cd /opt/Ingestion/iartnet/infra/docker/api/compose
ENV=/opt/Ingestion/iartnet/apps/api/.env
MIG=/opt/Ingestion/iartnet/apps/api/database/migrations

apply() {
  local f="$1"
  docker compose --env-file "$ENV" exec -T postgres \
    sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
    < "$f"
}

apply "$MIG/20260226_00_fts_published_fallback_it_FULL.sql"
apply "$MIG/20260226_05_google_like_search_v2.sql"
apply "$MIG/20260226_06_search_autocomplete.sql"
apply "$MIG/20260226_06b_fix_autocomplete_terms.sql"
```text
```

## A.2 Rebuild (prima volta)
```bash
docker compose --env-file "$ENV" exec -T postgres \
  sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT iartnet_master.record_search_en_rebuild_all();"'

docker compose --env-file "$ENV" exec -T postgres \
  sh -lc 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT iartnet_master.search_suggest_rebuild_all();"'
````

