# IARTNET - Delivery Package

## Obiettivo

Questa directory contiene gli artefatti compilati e pronti per il deployment in ambienti di **Test** e **Produzione**.

## Struttura

```
delivery/
├── README.md                    # Questa guida
├── build.sh                     # Script di build principale
├── build.ps1                    # Script di build per Windows
├── test/                        # Package per ambiente Test
│   ├── docker-compose.yml       # Docker Compose per test
│   ├── .env.example             # Template variabili ambiente test
│   └── iartnet-api/             # Package Laravel API compilato
│       ├── Dockerfile           # Dockerfile ottimizzato
│       └── [file compilati]
└── prod/                        # Package per ambiente Produzione
    ├── docker-compose.yml       # Docker Compose per produzione
    ├── .env.example             # Template variabili ambiente prod
    └── iartnet-api/             # Package Laravel API compilato
        ├── Dockerfile           # Dockerfile ottimizzato
        └── [file compilati]
```

## Processo di Build

### Prerequisiti

**Opzione 1: Build con Docker (Consigliato - Non richiede PHP/Composer locali)**
- Docker installato e funzionante
- Git (per versioning)

**Opzione 2: Build locale**
- Docker installato e funzionante
- Composer installato (per dipendenze PHP)
- Node.js e npm installati (per compilazione assets)
- Git (per versioning)

### Build per Test

**Con Docker (Windows/Linux/macOS):**
```bash
# Windows
.\delivery\build-docker.ps1 -Environment test

# Linux/macOS
./delivery/build-docker.sh test  # (se disponibile)
```

**Build locale:**
```bash
# Linux/macOS
./delivery/build.sh test

# Windows
.\delivery\build.ps1 -Environment test
```

### Build per Produzione

**Con Docker (Windows/Linux/macOS):**
```bash
# Windows
.\delivery\build-docker.ps1 -Environment prod

# Linux/macOS
./delivery/build-docker.sh prod  # (se disponibile)
```

**Build locale:**
```bash
# Linux/macOS
./delivery/build.sh prod

# Windows
.\delivery\build.ps1 -Environment prod
```

## Cosa Include il Build

Il processo di build:

1. **Pulisce** le directory di output precedenti
2. **Copia** i file sorgente necessari
3. **Installa** dipendenze Composer (senza dev)
4. **Compila** assets frontend (Vite)
5. **Ottimizza** Laravel (cache config, routes, views)
6. **Rimuove** file non necessari (tests, docs, dev tools)
7. **Crea** Dockerfile ottimizzato per l'ambiente
8. **Genera** docker-compose.yml per l'ambiente
9. **Crea** .env.example con variabili appropriate

## Deployment

### Test

```bash
cd delivery/test
cp .env.example .env
# Modifica .env con valori appropriati
docker compose up -d
```

### Produzione

```bash
cd delivery/prod
cp .env.example .env
# Modifica .env con valori di produzione (sicuri!)
docker compose up -d
```

## Variabili Ambiente

### Test

- `APP_ENV=testing`
- `APP_DEBUG=true` (per debugging)
- Database e Redis su porte standard

### Produzione

- `APP_ENV=production`
- `APP_DEBUG=false` (sicurezza)
- Database e Redis con configurazioni ottimizzate
- Logging strutturato
- Health checks attivi

## Verifica Post-Deployment

Dopo il deployment, verifica:

1. **Health check container**: `docker ps`
2. **Log applicazione**: `docker compose logs -f api`
3. **Connessione database**: `docker exec <container> php artisan db:show`
4. **Cache Redis**: `docker exec <container> php artisan tinker` → `Cache::get('test')`
5. **Endpoint API**: `curl http://localhost:8000/api/health` (se disponibile)

## Rollback

In caso di problemi:

```bash
# Ferma i container
docker compose down

# Ripristina versione precedente
# (da backup o da tag Git precedente)
git checkout <tag-precedente>
./delivery/build.sh <env>
cd delivery/<env>
docker compose up -d
```

## Best Practices

1. **Mai committare** i file compilati in `delivery/test/` e `delivery/prod/`
2. **Sempre testare** in ambiente test prima di produrre
3. **Versionare** i tag Git prima di ogni build produzione
4. **Documentare** le modifiche in CHANGELOG.md
5. **Backup database** prima di deployment produzione

## Troubleshooting

### Build fallisce

- Verifica che tutte le dipendenze siano installate
- Controlla i log: `./delivery/build.sh <env> 2>&1 | tee build.log`
- Verifica permessi file: `chmod +x delivery/build.sh`

### Container non si avvia

- Verifica log: `docker compose logs`
- Controlla variabili ambiente: `cat .env`
- Verifica porte disponibili: `netstat -tulpn | grep -E '5432|6379|8000'`

### Assets non caricati

- Verifica che `npm run build` sia stato eseguito
- Controlla permessi `storage/` e `bootstrap/cache/`
- Verifica che Vite abbia compilato correttamente

## Riferimenti

- [Local Development](../docs/runbooks/local-dev.md)
- [Architecture](../docs/architecture/README.md)
- [Release Guide](../docs/development/create-release.md)
