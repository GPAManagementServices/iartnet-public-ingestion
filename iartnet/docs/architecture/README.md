# Architettura IARTNET - Panoramica Completa

> **Versione Documentazione**: [v0.1.0-docs](https://github.com/GPAManagementServices/iartnet/releases/tag/v0.1.0-docs)  
> **Ultimo Aggiornamento**: 2026-01-11  
> **Branch di Riferimento**: `main` / `develop`

Questo documento descrive l'architettura completa del progetto IARTNET, suddivisa
in tre livelli: Filesystem, GitHub e Docker.

**Nota**: Questa documentazione si riferisce alla versione **v0.1.0-docs** del
progetto. Per versioni successive, consulta le release notes su GitHub.

## 1. Architettura Filesystem (Repository Locale)

### 1.1 Struttura Monorepo

Il progetto segue una strategia **Monorepo** per garantire decoupling tra i
livelli di elaborazione dati e presentazione pubblica.

```text
iartnet/
├── apps/                    # Applicazioni principali
│   ├── api/                 # Backend Laravel 12 + Filament 3
│   ├── etl/                 # Moduli ETL (Extract, Transform, Load)
│   └── web/                 # Frontend Nuxt 4 (SSR)
│
├── packages/                # Pacchetti condivisi
│   └── shared/              # Definizioni TypeScript e logica business
│
├── infra/                   # Infrastruttura
│   ├── docker/              # Configurazione Docker
│   │   ├── docker-compose.yml
│   │   └── Dockerfile.api
│   └── scripts/             # Script infrastrutturali
│
├── scripts/                 # Script di sviluppo e automazione
│   ├── bash/                # Script Bash (Linux/WSL/macOS)
│   │   ├── dev-init.sh      # Inizializzazione ambiente
│   │   ├── dev-up.sh        # Avvio servizi Docker
│   │   ├── dev-down.sh      # Arresto servizi Docker
│   │   ├── lint-local.sh    # Linting locale
│   │   └── pre-commit-security.sh  # Security scan pre-commit
│   │
│   └── ps1/                 # Script PowerShell (Windows)
│       ├── dev-init.ps1
│       ├── dev-up.ps1
│       ├── dev-down.ps1
│       ├── lint-local.ps1
│       ├── pre-commit-security.ps1
│       ├── setup-pre-commit.ps1
│       └── test-pre-commit-hook.ps1
│
├── docs/                    # Documentazione
│   ├── adr/                 # Architecture Decision Records
│   ├── development/         # Guide sviluppo
│   ├── qa/                  # Test plan e QA
│   ├── requirements/        # Requisiti e vincoli
│   ├── runbooks/            # Runbook operativi
│   ├── security/            # Documentazione sicurezza
│   └── traceability/        # Matrice tracciabilità
│
├── .github/                 # Configurazione GitHub
│   ├── workflows/           # GitHub Actions workflows
│   └── dependabot.yml       # Configurazione Dependabot
│
└── .git/hooks/              # Git hooks
    └── pre-commit           # Hook pre-commit (security + linting)
```

### 1.2 Pre-Commit Hook

Il sistema include un **pre-commit hook** che esegue automaticamente:

1. **Security Scan (Trivy)**:
   - Scansione filesystem per vulnerabilità
   - Scansione repository per secrets esposti
   - Scansione Dockerfiles

2. **Local Linting**:
   - Bash/Shell: `shfmt`, `shellcheck`
   - PowerShell: `PSScriptAnalyzer`
   - Markdown: `markdownlint`
   - Docker: `hadolint`
   - YAML: `yamllint`

**Configurazione**: Eseguire `.\scripts\ps1\setup-pre-commit.ps1` o
`./scripts/bash/setup-pre-commit.sh`

### 1.3 Script di Sviluppo

#### Windows (PowerShell)
- `dev-init.ps1`: Inizializza ambiente completo
- `dev-up.ps1`: Avvia servizi Docker
- `dev-down.ps1`: Arresta servizi Docker
- `lint-local.ps1`: Esegue linting locale

#### Linux/WSL/macOS (Bash)
- `dev-init.sh`: Inizializza ambiente completo
- `dev-up.sh`: Avvia servizi Docker
- `dev-down.sh`: Arresta servizi Docker
- `lint-local.sh`: Esegue linting locale

## 2. Architettura GitHub (CI/CD e Sicurezza)

### 2.1 GitHub Actions Workflows

#### 2.1.1 CI Workflow (`.github/workflows/ci.yml`)

**Trigger**: Pull Request su `main`/`develop`, push su `develop`

**Job**: `Standard-Validation`
- Esegue Super-Linter per validazione codice
- Verifica conformità standard (PSR12 per PHP, etc.)
- Blocca merge se fallisce

**Permessi**:
- `contents: read`
- `pull-requests: write`
- `checks: write`

#### 2.1.2 Linter Workflow (`.github/workflows/linter.yml`)

**Trigger**: Push/PR su `main`/`develop`/`master`, manuale

**Job**: `Lint Code Base`
- Esegue Super-Linter v5 con validazione:
  - PHP (PSR12 standard)
  - YAML, JSON, Markdown
  - Docker (Hadolint)
  - PowerShell (PSScriptAnalyzer)
  - Bash/Shell (shfmt, shellcheck)
- Upload risultati come artifact

**Configurazione**:
- `VALIDATE_ALL_CODEBASE: true`
- `LOG_LEVEL: VERBOSE`
- `DISABLE_ERRORS: false`

#### 2.1.3 Security Workflow (`.github/workflows/security.yml`)

**Trigger**: Push/PR su `main`/`develop`/`master`, schedulato (settimanale),
manuale

**Jobs**:

1. **Trivy Scan** (matrix: `fs`, `repo`):
   - Scansione filesystem per vulnerabilità
   - Scansione repository per secrets
   - Upload risultati SARIF a GitHub Security

2. **Trivy Docker**:
   - Scansione Dockerfiles per vulnerabilità
   - Upload risultati SARIF

3. **Dependency Check**:
   - Composer audit (PHP)
   - npm audit (Node.js)
   - Upload risultati come artifact

**Severità**: `CRITICAL,HIGH`
**Exit Code**: `0` (non blocca build, solo report)

### 2.2 Dependabot (`.github/dependabot.yml`)

Configurazione per aggiornamenti automatici dipendenze:

- **GitHub Actions**: Settimanale, max 10 PR
- **npm** (`apps/web`): Settimanale, max 5 PR, ignora major
- **Composer** (`apps/api`): Settimanale, max 5 PR, ignora major Laravel/Filament
- **Docker** (`infra/docker`): Settimanale, max 3 PR

**Reviewers**: `GPAManagementServices/developers`
**Labels**: `dependencies`, `frontend`/`backend`/`docker`

### 2.3 GitHub Secrets

Secrets configurati per workflow CI (vedi
`docs/security/github-secrets-setup.md`):

- `POSTGRES_TEST_PASSWORD`: Password database test
- `POSTGRES_TEST_USER`: Utente database test
- `POSTGRES_TEST_DB`: Nome database test

**Best Practice**: Mai hardcodare secrets nei workflow, sempre usare
`secrets.*`

### 2.4 Branch Protection Rules

Configurazione consigliata (vedi
`docs/security/branch-protection-setup.md`):

**Per `main` e `develop`**:
- Require pull request before merging
- Require approvals: 1
- Require status checks:
  - `Standard-Validation`
  - `Super-Linter` (o `Lint Code Base`)
  - `Trivy Security Scan`
- Require branches to be up to date
- Include administrators
- Do not allow bypassing
- Block force pushes
- Block deletions

### 2.5 Security Policy

File `SECURITY.md` presente con:
- Policy di disclosure responsabile
- Contatti per segnalazioni
- Processo di gestione vulnerabilità

## 3. Architettura Docker

### 3.1 Docker Compose (`infra/docker/docker-compose.yml`)

#### 3.1.1 Servizi Principali

**PostgreSQL 16 (Alpine)**:
- Container: `iartnet-db`
- Porta: `5432` (configurabile via `POSTGRES_PORT`)
- Database: `iartnet_master` (default)
- User: `iartnet` (default)
- Volume persistente: `postgres_data`
- Healthcheck: `pg_isready`
- Network: `iartnet-network`

**Redis 7 (Alpine)**:
- Container: `iartnet-redis`
- Porta: `6379` (configurabile via `REDIS_PORT`)
- Volume persistente: `redis_data`
- Healthcheck: `redis-cli ping`
- Network: `iartnet-network`

**MinIO** (opzionale, commentato):
- Per object storage (IIIF/media)
- Porte: `9000` (API), `9001` (Console)
- Attivabile decommentando nel docker-compose.yml

#### 3.1.2 Network

**`iartnet-network`**:
- Tipo: `bridge`
- Isola i container dall'host
- Consente comunicazione tra servizi

#### 3.1.3 Volumes

- `postgres_data`: Dati PostgreSQL persistenti
- `redis_data`: Dati Redis persistenti
- `minio_data`: Object storage (se attivato)

### 3.2 Dockerfile API (`infra/docker/Dockerfile.api`)

**Base Image**: `php:8.4-fpm-alpine`

**Estensioni PHP**:
- `bcmath`: Calcoli matematici precisi
- `intl`: Internazionalizzazione
- `pdo_pgsql`: Driver PostgreSQL
- `zip`: Gestione archivi

**Composer**:
- Versione: `2.7` (pinned)
- Copiato da immagine ufficiale

**Configurazione**:
- Workdir: `/var/www/html`
- User: `www-data` (non root)
- CMD: `php-fpm`

**Best Practices**:
- Multi-stage build per Composer
- Non-root user
- Immagine Alpine (minimal)
- Versioni pinned dove possibile

### 3.3 Variabili d'Ambiente

Configurazione via `.env` (non committato):

**PostgreSQL**:
- `POSTGRES_DB`: Nome database
- `POSTGRES_USER`: Utente database
- `POSTGRES_PASSWORD`: Password database
- `POSTGRES_PORT`: Porta esposta

**Redis**:
- `REDIS_PORT`: Porta esposta

**MinIO** (se attivato):
- `MINIO_ROOT_USER`: Utente admin
- `MINIO_ROOT_PASSWORD`: Password admin
- `MINIO_PORT`: Porta API
- `MINIO_CONSOLE_PORT`: Porta console

### 3.4 Health Checks

Tutti i servizi includono health checks per:
- Verifica disponibilità
- Restart automatico se non healthy
- Integrazione con orchestrazione

## 4. Guida Completa per Nuovi Developer

### 4.1 Prerequisiti

Prima di iniziare, assicurati di avere installato:

**Obbligatori**:
- **Git** (versione 2.30+)
- **Docker Desktop** (Windows/macOS) o **Docker Engine** (Linux)
- **Node.js** 22+ (per frontend Nuxt)
- **PHP** 8.4+ con Composer (per backend Laravel)
- **PowerShell** 7+ (Windows) o **Bash** (Linux/WSL/macOS)

**Opzionali ma consigliati**:
- **GitHub CLI** (`gh`) per gestione PR e workflow
- **Trivy** per security scanning locale
- **PSScriptAnalyzer** (PowerShell) per linting locale
- **markdownlint-cli** per validazione Markdown

### 4.2 Setup Iniziale - Step by Step

#### Step 1: Clone del Repository

```bash
# Clona il repository
git clone https://github.com/GPAManagementServices/iartnet.git
cd iartnet

# Verifica di essere sul branch corretto
git checkout develop  # o il branch assegnato
```

#### Step 2: Verifica Prerequisiti

**Windows (PowerShell)**:
```powershell
# Verifica Docker
docker --version
docker ps  # Deve funzionare senza errori

# Verifica Git
git --version

# Verifica Node.js
node --version  # Deve essere 22+

# Verifica PHP
php --version  # Deve essere 8.4+
composer --version
```

**Linux/WSL/macOS (Bash)**:
```bash
# Verifica Docker
docker --version
docker ps

# Verifica Git
git --version

# Verifica Node.js
node --version

# Verifica PHP
php --version
composer --version
```

#### Step 3: Inizializzazione Ambiente

**Windows (PowerShell)**:
```powershell
# Esegui script di inizializzazione
.\scripts\ps1\dev-init.ps1
```

**Linux/WSL/macOS (Bash)**:
```bash
# Rendi eseguibili gli script
chmod +x scripts/bash/*.sh

# Esegui script di inizializzazione
./scripts/bash/dev-init.sh
```

Lo script eseguirà automaticamente:
- Verifica prerequisiti (Docker, Git)
- Creazione `.env` da `.env.example` (se non esiste)
- Avvio servizi Docker (PostgreSQL, Redis)
- Attesa che PostgreSQL sia pronto

#### Step 4: Configurazione Variabili d'Ambiente

Se necessario, modifica il file `.env` nella root del progetto:

```bash
# Apri .env con il tuo editor preferito
# Modifica almeno:
POSTGRES_PASSWORD=<password-sicura>
POSTGRES_USER=iartnet
POSTGRES_DB=iartnet_master
```

**Importante**: Non committare mai il file `.env`!

#### Step 5: Setup Pre-Commit Hook

**Windows (PowerShell)**:
```powershell
.\scripts\ps1\setup-pre-commit.ps1
```

**Linux/WSL/macOS (Bash)**:
```bash
# Il hook viene creato automaticamente da dev-init.sh
# Per verificare:
ls -la .git/hooks/pre-commit
```

Il pre-commit hook eseguirà automaticamente:
- Security scan con Trivy (se installato)
- Linting locale (se tool installati)

#### Step 6: Setup Backend (Laravel API)

```bash
cd apps/api

# Installa dipendenze PHP
composer install

# Genera chiave applicazione
php artisan key:generate

# Esegui migrazioni database
php artisan migrate

# (Opzionale) Popola database con dati di test
php artisan db:seed
```

**Verifica connessione database**:
- Assicurati che Docker sia in esecuzione
- Verifica che PostgreSQL sia pronto: `docker exec iartnet-db pg_isready -U iartnet`
- Controlla `.env` in `apps/api` che punti a `POSTGRES_HOST=postgres`

#### Step 7: Setup Frontend (Nuxt Web)

```bash
cd apps/web

# Installa dipendenze Node.js
npm install

# Avvia server di sviluppo
npm run dev
```

Il frontend sarà disponibile su `http://localhost:3000` (porta di default Nuxt).

#### Step 8: Verifica Installazione

**Test Backend**:
```bash
cd apps/api
php artisan --version  # Verifica Laravel
php artisan route:list  # Lista route disponibili
```

**Test Frontend**:
```bash
cd apps/web
npm run build  # Test build di produzione
```

**Test Docker Services**:
```bash
# Verifica container attivi
docker ps

# Dovresti vedere:
# - iartnet-db (PostgreSQL)
# - iartnet-redis (Redis)

# Verifica connessione database
docker exec iartnet-db psql -U iartnet -d iartnet_master -c "SELECT version();"
```

### 4.3 Workflow di Sviluppo Quotidiano

#### Avvio Ambiente

**Windows (PowerShell)**:
```powershell
.\scripts\ps1\dev-up.ps1
```

**Linux/WSL/macOS (Bash)**:
```bash
./scripts/bash/dev-up.sh
```

#### Sviluppo

1. **Crea un branch** per la feature/bugfix:
   ```bash
   git checkout -b feature/nome-feature
   # o
   git checkout -b bugfix/nome-bugfix
   ```

2. **Sviluppa** nel codice:
   - Backend: `apps/api/`
   - Frontend: `apps/web/`
   - ETL: `apps/etl/`

3. **Testa localmente**:
   - Backend: `php artisan test`
   - Frontend: `npm run test`

4. **Commit**:
   ```bash
   git add .
   git commit -m "feat: descrizione feature"
   ```
   Il pre-commit hook eseguirà automaticamente security scan e linting.

5. **Push e crea PR**:
   ```bash
   git push origin feature/nome-feature
   # Poi crea PR su GitHub
   ```

#### Arresto Ambiente

**Windows (PowerShell)**:
```powershell
.\scripts\ps1\dev-down.ps1
```

**Linux/WSL/macOS (Bash)**:
```bash
./scripts/bash/dev-down.sh
```

### 4.4 Troubleshooting Comune

#### Docker non si avvia

**Sintomi**: `ERROR: Docker daemon is not running!`

**Soluzione**:
1. Avvia Docker Desktop (Windows/macOS)
2. Attendi che Docker sia completamente inizializzato
3. Verifica con: `docker ps`

#### Database connection error

**Sintomi**: `SQLSTATE[08006] [7] could not connect to server`

**Soluzione**:
1. Verifica che PostgreSQL sia in esecuzione: `docker ps | grep iartnet-db`
2. Verifica che sia pronto: `docker exec iartnet-db pg_isready -U iartnet`
3. Controlla `.env` in `apps/api`:
   - `DB_HOST=postgres` (nome container, non localhost)
   - `DB_PORT=5432`
   - `DB_DATABASE=iartnet_master`
   - `DB_USERNAME=iartnet`
   - `DB_PASSWORD=<password-da-.env-root>`

#### Porta già in uso

**Sintomi**: `Error: bind: address already in use`

**Soluzione**:
1. Identifica processo che usa la porta:
   ```bash
   # Windows
   netstat -ano | findstr :5432
   
   # Linux/macOS
   lsof -i :5432
   ```
2. Modifica porta in `.env`:
   ```env
   POSTGRES_PORT=5433
   ```

#### Pre-commit hook fallisce

**Sintomi**: `ERROR: Pre-commit security check failed!`

**Soluzione**:
1. Verifica errori specifici nel messaggio
2. Se Trivy non è installato, è normale (skip automatico)
3. Se linting fallisce, correggi errori o usa `git commit --no-verify` (non
   raccomandato)
4. Installa tool mancanti (vedi sezione 4.5)

### 4.5 Installazione Tool Opzionali

#### Trivy (Security Scanning)

**Windows (Scoop)**:
```powershell
scoop install trivy
```

**Linux/macOS**:
```bash
# macOS
brew install trivy

# Linux
sudo apt-get update
sudo apt-get install wget apt-transport-https gnupg lsb-release
wget -qO - https://aquasecurity.github.io/trivy-repo/deb/public.key | sudo apt-key add -
echo "deb https://aquasecurity.github.io/trivy-repo/deb $(lsb_release -sc) main" | sudo tee -a /etc/apt/sources.list.d/trivy.list
sudo apt-get update
sudo apt-get install trivy
```

#### PSScriptAnalyzer (PowerShell Linting)

**PowerShell**:
```powershell
Install-Module -Name PSScriptAnalyzer -Scope CurrentUser -Force
```

#### markdownlint-cli (Markdown Linting)

**npm (globale)**:
```bash
npm install -g markdownlint-cli
```

#### shfmt e hadolint (Shell/Docker Linting)

**Windows (Scoop)**:
```powershell
scoop install shfmt hadolint
```

**Linux/macOS**:
```bash
# macOS
brew install shfmt hadolint

# Linux
# shfmt
go install mvdan.cc/sh/v3/cmd/shfmt@latest

# hadolint
wget -O /usr/local/bin/hadolint https://github.com/hadolint/hadolint/releases/latest/download/hadolint-Linux-x86_64
chmod +x /usr/local/bin/hadolint
```

## 5. Guida per Developer che Usano Cursor

### 5.1 Setup Cursor per IARTNET

#### Configurazione Workspace

Cursor riconosce automaticamente la struttura del progetto. Per ottimizzare
l'esperienza:

1. **Apri workspace**:
   - File → Open Folder → Seleziona cartella `iartnet`

2. **Configurazione estensioni consigliate**:
   - **PHP**: Estensione PHP Intelephense o PHP IntelliSense
   - **Laravel**: Laravel Extension Pack
   - **Vue/Nuxt**: Volar (Vue Language Features)
   - **Docker**: Docker extension
   - **Git**: GitLens (opzionale)

3. **File `.vscode/settings.json`** (se necessario):
   ```json
   {
     "php.validate.executablePath": "php",
     "php.suggest.basic": false,
     "editor.formatOnSave": true,
     "editor.codeActionsOnSave": {
       "source.fixAll": true
     },
     "files.exclude": {
       "**/.git": true,
       "**/node_modules": true,
       "**/vendor": true
     }
   }
   ```

### 5.2 Utilizzo di Cursor AI per IARTNET

#### Comandi Utili

**Generazione Codice**:
- `@codebase`: Riferimento a tutto il codebase
- `@docs`: Riferimento alla documentazione
- `@apps/api`: Riferimento al backend Laravel
- `@apps/web`: Riferimento al frontend Nuxt

**Esempi di Prompt**:
```text
@codebase Come funziona l'autenticazione in Laravel?
@apps/api Genera un controller per gestire le opere d'arte
@docs Quali sono i requisiti per il database?
```

#### Best Practices con Cursor

1. **Usa @codebase per contesto**:
   ```text
   @codebase Voglio aggiungere una nuova route API per le mostre.
   Quali pattern seguono le route esistenti?
   ```

2. **Riferimenti specifici**:
   ```text
   @apps/api/models/Artwork.php Modifica questo model per aggiungere
   un campo "exhibition_id"
   ```

3. **Documentazione inline**:
   ```text
   @docs/requirements/README.md Questo requisito è implementato?
   ```

### 5.3 Integrazione Cursor con Git

#### Pre-Commit Hook

Cursor rispetta automaticamente i pre-commit hooks. Quando fai commit:

1. Cursor esegue automaticamente il hook
2. Se fallisce, mostra errori nel terminale integrato
3. Puoi correggere direttamente in Cursor

#### Gestione Branch

Cursor ha integrazione Git nativa:
- **Source Control** panel (icona Git nella sidebar)
- **Branch switching** dal menu in basso
- **Diff view** integrato

**Workflow consigliato**:
1. Crea branch da Cursor: `git checkout -b feature/nome`
2. Sviluppa con AI assist
3. Commit da Cursor (trigger hook automatico)
4. Push e crea PR da GitHub CLI o web

### 5.4 Debugging con Cursor

#### Backend Laravel

**Configurazione Launch** (`.vscode/launch.json`):
```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/var/www/html": "${workspaceFolder}/apps/api"
      }
    }
  ]
}
```

**Breakpoints**:
- Imposta breakpoint nel codice PHP
- Avvia debugger (F5)
- Esegui test o richiesta API

#### Frontend Nuxt

**Debug Browser**:
1. Apri DevTools (F12)
2. Cursor integra source maps automaticamente
3. Breakpoint funzionano nel codice TypeScript/Vue

**Nuxt DevTools**:
- Disponibile su `http://localhost:3000/_nuxt/devtools`
- Inspect componenti, route, state

### 5.5 Cursor AI per Code Review

#### Pre-Commit Review

Prima di committare, usa Cursor per:
```text
@codebase Review questo codice per:
- Conformità PSR12
- Best practices Laravel
- Sicurezza (SQL injection, XSS)
```

#### Refactoring Assistito

```text
@apps/api/app/Http/Controllers/ArtworkController.php
Refactorizza questo controller seguendo i pattern del progetto.
Usa repository pattern se applicabile.
```

### 5.6 Snippet e Template

Cursor può generare snippet personalizzati. Esempi:

**Laravel Controller**:
```text
@codebase Genera un controller Laravel seguendo il pattern dei
controller esistenti. Include:
- CRUD completo
- Validazione request
- Response JSON standardizzata
```

**Nuxt Component**:
```text
@apps/web/components Genera un componente Vue 3 con:
- TypeScript
- Composition API
- Accessibilità WCAG 2.1 AA
```

### 5.7 Integrazione Terminale

Cursor include terminale integrato. Comandi utili:

**PowerShell (Windows)**:
```powershell
# Avvia servizi
.\scripts\ps1\dev-up.ps1

# Linting locale
.\scripts\ps1\lint-local.ps1

# Test pre-commit hook
.\scripts\ps1\test-pre-commit-hook.ps1
```

**Bash (Linux/WSL/macOS)**:
```bash
# Avvia servizi
./scripts/bash/dev-up.sh

# Linting locale
./scripts/bash/lint-local.sh
```

### 5.8 Troubleshooting Cursor

#### AI non risponde correttamente

**Soluzione**:
1. Verifica connessione internet
2. Riavvia Cursor
3. Usa prompt più specifici con @riferimenti

#### IntelliSense non funziona

**Sintomi**: Nessun autocompletamento per PHP/TypeScript

**Soluzione**:
1. Installa estensioni appropriate
2. Riavvia Cursor
3. Verifica che i file siano nella workspace corretta

#### Pre-commit hook non esegue

**Sintomi**: Commit va a buon fine senza eseguire hook

**Soluzione**:
1. Verifica che hook esista: `ls -la .git/hooks/pre-commit`
2. Rendi eseguibile: `chmod +x .git/hooks/pre-commit`
3. Esegui manualmente: `.\scripts\ps1\test-pre-commit-hook.ps1`

## 6. Flusso di Sviluppo Completo

### 6.1 Setup Iniziale

1. **Clone repository**
2. **Esegui script init**:
   - Windows: `.\scripts\ps1\dev-init.ps1`
   - Linux/WSL: `./scripts/bash/dev-init.sh`
3. **Configura `.env`** (se necessario)
4. **Setup pre-commit hook**: `.\scripts\ps1\setup-pre-commit.ps1`

### 6.2 Sviluppo Locale

1. **Avvia servizi**: `.\scripts\ps1\dev-up.ps1`
2. **Sviluppa** in `apps/api` o `apps/web`
3. **Commit**: Pre-commit hook esegue automaticamente:
   - Security scan (Trivy)
   - Linting locale (se tool installati)
4. **Push**: Trigger GitHub Actions workflows

### 6.3 CI/CD Pipeline

1. **Push/PR** → Trigger workflows
2. **Super-Linter** → Valida codice
3. **Security Scan** → Verifica vulnerabilità
4. **Status Checks** → Blocca merge se falliscono
5. **Merge** → Solo se tutti i check passano

## 7. Sicurezza

### 5.1 Pre-Commit
- Trivy security scan automatico
- Linting locale (opzionale ma consigliato)

### 5.2 GitHub Actions
- Trivy scans (filesystem, repo, Docker)
- Dependency audits (Composer, npm)
- SARIF upload a GitHub Security

### 5.3 Best Practices
- Nessun secret hardcoded
- GitHub Secrets per valori sensibili
- Branch protection attiva
- Pre-commit hooks obbligatori
- Security policy documentata

## 8. Documentazione

Tutta la documentazione è in `docs/`:

- **ADR**: Decisioni architetturali
- **Development**: Guide setup e sviluppo
- **Security**: Configurazione sicurezza
- **Runbooks**: Procedure operative
- **Requirements**: Requisiti e vincoli
- **QA**: Test plan e strategia testing

## 9. Riferimenti

- [Local Development Runbook](runbooks/local-dev.md)
- [Security Setup](security/README.md)
- [GitHub CLI Setup](development/github-cli-setup.md)
- [Lint Local Setup](development/lint-local-setup.md)
- [Branch Protection](security/branch-protection-setup.md)
- [GitHub Secrets](security/github-secrets-setup.md)
- [GitHub Wiki Setup](development/github-wiki-setup.md)

## 10. Integrazione GitHub Wiki

Questo documento può essere integrato nella **GitHub Wiki** del progetto per
renderlo facilmente accessibile e modificabile.

**Vantaggi Wiki**:
- Accesso diretto da interfaccia GitHub
- Modifiche collaborative semplificate
- Navigazione integrata
- Repository Git separato

**Procedura**:
1. Abilita Wiki nelle impostazioni del repository
2. Clona repository Wiki: `git clone
   https://github.com/GPAManagementServices/iartnet.wiki.git`
3. Copia questo file come `Architecture.md`
4. Adatta link interni (vedi `docs/development/github-wiki-setup.md`)

Per dettagli completi, consulta la guida:
[GitHub Wiki Setup](development/github-wiki-setup.md)
