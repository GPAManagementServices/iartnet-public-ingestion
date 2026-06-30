# IARTNET API — Runbook Deploy (infra/docker stack)

**Scope:** deploy API IARTNET su stack Docker Compose: `infra/docker/api/compose/docker-compose.yml`  
**Script:** `apps/api/infra/deploy/deploy_api.sh`

## Strategia CI/CD: Pull-based deploy (server build)

- Un job (GitHub Actions) fa SSH sul server e lancia `deploy_api.sh` (fetch/checkout ref → compose up → migrate → smoke).
- Pro: niente registry, tracciabilità per SHA, stesso flow per staging/prod.
- Contro: build sul server (carico/tempo). In futuro: image-based.

## Prerequisiti (OBBLIGATORI)

- Docker Engine + Docker Compose v2
- Repo sul server: `/opt/Ingestion/iartnet` (o `DEPLOY_REPO_PATH`)
- **POSTGRES_PASSWORD** obbligatoria: impostare in `apps/api/.env` (o ambiente). Il compose fallisce se assente.
- File `apps/api/.env` presente e protetto:
  ```bash
  chmod 600 /opt/Ingestion/iartnet/apps/api/.env
  ```
- Comandi: `docker`, `git`, `curl`, `awk`, `flock` disponibili.

## Script: opzioni e lock

- **Lock anti-concorrenza:** lo script usa `flock` su `.deploy_api.lock` (in repo root). Un solo deploy per volta; se un altro è in corso, lo script termina con errore.
- **Worktree sporco:** se il working tree è sporco, lo script fallisce a meno di usare `--force-reset` (esegue `git reset --hard` e `git clean -fd`).
- **Ref tracciati solo a successo:** `.deploy_last_ref` e `.deploy_previous_ref` vengono aggiornati **solo al termine di un deploy riuscito** (non in caso di errore a metà).

Opzioni principali:

| Opzione | Descrizione |
|--------|-------------|
| `--ref BRANCH\|TAG\|SHA` | Deploy di questo ref (default: HEAD). |
| `--no-build` | Salta build immagine Docker. |
| `--with-db-restore` | Restore DB da `dbBackup/iartnet1_rebuild.sql`; **richiede `--yes`**. |
| `--db-recreate` | DROP/CREATE DB prima del restore (DESTRUTTIVO); **richiede `--with-db-restore --yes`**. |
| `--force-reset` | Se worktree sporco: reset + clean. |
| `--yes` | Conferma non interattiva (obbligatorio per restore / db-recreate). |
| `--rollback` | Ripristina codice al ref precedente e riavvia lo stack. **Il DB non viene ripristinato automaticamente.** |

## Esempi comandi

```bash
cd /opt/Ingestion/iartnet
./apps/api/infra/deploy/deploy_api.sh --ref main
./apps/api/infra/deploy/deploy_api.sh --ref v1.0.0 --no-build
./apps/api/infra/deploy/deploy_api.sh --with-db-restore --yes
./apps/api/infra/deploy/deploy_api.sh --with-db-restore --db-recreate --yes
./apps/api/infra/deploy/deploy_api.sh --rollback
```

## Rollback “in 3 minuti”

1. **Solo codice:**  
   `./apps/api/infra/deploy/deploy_api.sh --rollback`  
   (checkout del ref in `.deploy_previous_ref`, compose up --build, migrate, smoke).  
   **Nota:** il database **non** viene ripristinato; solo il codice torna alla versione precedente.

2. **Se il worktree è sporco:** fare stash/reset manuale oppure usare prima un deploy con `--force-reset` (attenzione: scarta modifiche locali).

3. **Rollback manuale:**  
   `git checkout <sha-precedente>` poi  
   `docker compose -f infra/docker/api/compose/docker-compose.yml up -d --build`

## Disaster recovery DB

- **Backup pre-restore:** lo script salva sempre un dump in `dbBackup/pre_restore/pre_restore_YYYYMMDD_HHMMSS.sql` prima di ogni restore.
- **Ripristino da backup:**  
  ```bash
  docker compose -f infra/docker/api/compose/docker-compose.yml exec -T postgres psql -U iartnet -d iartnet1 < dbBackup/pre_restore/pre_restore_YYYYMMDD_HHMMSS.sql
  ```  
  (oppure usare `pg_restore` se il dump è in formato custom). Poi: `php artisan migrate --force` nel container app.
- **DB completamente da rifare:**  
  `./apps/api/infra/deploy/deploy_api.sh --with-db-restore --db-recreate --yes`  
  (attenzione: DROP/CREATE del database).

## Procedura DB restore (sicura)

1. Backup automatico in `dbBackup/pre_restore/` (o `DB_BACKUP_DIR`).
2. Conferma esplicita: `--with-db-restore --yes`.
3. Restore in streaming (nessun file temporaneo gigante); filtrati solo statement DB-level distruttivi e meta-comandi psql.
4. Opzione `--db-recreate`: DROP/CREATE DB prima del restore (solo con `--with-db-restore --yes`).

## Verifiche post-deploy (evidenze)

- **Compose valido:**  
  `docker compose -f infra/docker/api/compose/docker-compose.yml config`
- **Health:**  
  `curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:8088/up` → 200
- **Log:**  
  `docker compose -f infra/docker/api/compose/docker-compose.yml logs --tail=50 app`

## Troubleshooting

- **Lock bloccato:** rimuovere `.deploy_api.lock` solo se sicuri che nessun deploy sia in esecuzione.
- **POSTGRES_PASSWORD mancante:** impostare in `apps/api/.env`; il compose e lo script la richiedono.
- **Nginx healthcheck /up:** allineato allo smoke test (Laravel route `health: '/up'` in `bootstrap/app.php`).
- **Container Postgres:** lo script risolve il container con `compose ps -q postgres` (nessun nome hardcoded).

## Riferimenti

- Compose: `iartnet/infra/docker/api/compose/docker-compose.yml`
- Health: `apps/api/bootstrap/app.php` → `health: '/up'`
- Checklist: `apps/api/infra/deploy/CHECKLIST_RELEASE.md`
