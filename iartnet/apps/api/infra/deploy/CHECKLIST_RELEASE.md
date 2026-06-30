# IARTNET API — Checklist di rilascio e criteri di accettazione

## 1) Pre-deploy

- [ ] Ref deciso (branch/tag/SHA)
- [ ] Repo sul server aggiornabile (permessi ok, spazio disco ok)
- [ ] `apps/api/.env` presente e protetto (`chmod 600`)
- [ ] **POSTGRES_PASSWORD** valorizzata (OBBLIGATORIA; compose e script falliscono se manca)
- [ ] (Consigliato) `docker compose config` passa:
  ```bash
  cd /opt/Ingestion/iartnet
  docker compose -f infra/docker/api/compose/docker-compose.yml config
  ```

## 2) Esecuzione deploy

- [ ] Esecuzione da root del repo
- [ ] Comando registrato (es. `./apps/api/infra/deploy/deploy_api.sh --ref v1.0.0`)
- [ ] Exit code **0**
- [ ] Nessun deploy concorrente (lock `.deploy_api.lock`)

## 3) Post-deploy — evidenze verificabili

- [ ] **Compose config:**  
  `docker compose -f infra/docker/api/compose/docker-compose.yml config` → nessun errore
- [ ] **Health endpoint:**  
  `curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1:8088/up` → **200**
- [ ] **Servizi up:**  
  `docker compose -f infra/docker/api/compose/docker-compose.yml ps` → postgres, redis, app, nginx running/healthy
- [ ] **Log senza errori critici:**  
  `docker compose -f infra/docker/api/compose/docker-compose.yml logs --tail=100 app`
- [ ] **Ref tracciati:**  
  `.deploy_last_ref` e `.deploy_previous_ref` aggiornati (solo se deploy completato con successo)

## 4) Criteri di accettazione (release)

- Script termina con **exit code 0**
- **Smoke test** (curl `/up`) **HTTP 200**
- Containers (postgres, redis, app, nginx) **running** e, dove previsto, **healthy**
- Migrazioni applicate senza errori
- Log applicazione senza errori bloccanti

## 5) Rollback

- **Solo codice:**  
  `./apps/api/infra/deploy/deploy_api.sh --rollback`  
  (ripristina ref precedente e riavvia stack; **DB non rollback automatico**)
- **Disaster recovery DB:** vedi RUNBOOK_DEPLOY.md (restore da `dbBackup/pre_restore/` o `--with-db-restore --db-recreate --yes`)

## 6) DB restore (opzionale)

- Solo con `--with-db-restore --yes`
- Backup pre-restore verificato in `dbBackup/pre_restore/`
- Dopo restore: migrate + smoke test come sopra
