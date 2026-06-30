# IARTNET - Senior DevOps Build & Deploy Script v37.0
# Caratteristiche: SQL Restore Locale Fix + UUID Structural Patch + Atomic Context

param(
    [Parameter(Mandatory=$false)]
    [ValidateSet("test", "prod")]
    [string]$Environment = "test"
)

$ErrorActionPreference = "Stop"
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false
function Write-Log { param($Msg, $Col = "Cyan") Write-Host "[$(Get-Date -Format 'HH:mm:ss')] $Msg" -ForegroundColor $Col }

# --- 1. CONFIGURAZIONE PERCORSI ---
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$EnvDir = Join-Path $ScriptDir $Environment
$ApiSource = Join-Path $ProjectRoot "apps\api"
$ApiTarget = Join-Path $EnvDir "iartnet-api"
$LocalTempBuild = Join-Path $ScriptDir ".tmp_build"
$SqlDumpPath = Join-Path $ScriptDir "iartnet1_rebuild.sql"

Write-Log "Inizio procedura di build IARTNET v37.0..."

# --- 2. PREPARAZIONE SORGENTI E COMPILAZIONE ASSET ---
if (Test-Path $LocalTempBuild) { Remove-Item -Recurse -Force $LocalTempBuild }
New-Item -ItemType Directory -Path $LocalTempBuild -Force | Out-Null

Write-Log "Fase 1: Preparazione sorgenti e compilazione dipendenze..."
$dirs = @("app", "bootstrap", "config", "database", "public", "resources", "routes", "storage")
foreach ($d in $dirs) { if (Test-Path "$ApiSource\$d") { Copy-Item -Recurse "$ApiSource\$d" "$LocalTempBuild\$d" } }
$files = @("artisan", "composer.json", "composer.lock", "package.json", "package-lock.json", "vite.config.js")
foreach ($f in $files) { if (Test-Path "$ApiSource\$f") { Copy-Item "$ApiSource\$f" "$LocalTempBuild\$f" } }

$ResolvedPath = (Resolve-Path $LocalTempBuild).Path
docker run --rm -v "${ResolvedPath}:/app" -w /app composer:2.7 install --no-dev --optimize-autoloader --ignore-platform-reqs
docker run --rm -v "${ResolvedPath}:/app" -w /app node:20-alpine sh -c "npm install && npm run build -- --emptyOutDir"
if (Test-Path (Join-Path $LocalTempBuild "node_modules")) { Remove-Item -Recurse -Force (Join-Path $LocalTempBuild "node_modules") }

# --- 3. DEFINIZIONE TEMPLATE CONFIGURAZIONE ---
$PhpMainConfig = "[global]`nerror_log = /var/log/php-fpm.log`ndaemonize = no`n[www]`nuser = www-data`ngroup = www-data`nlisten = /run/php-fpm.sock`nlisten.owner = www-data`nlisten.group = www-data`nlisten.mode = 0666`npm = dynamic`npm.max_children = 5`npm.start_servers = 2`npm.min_spare_servers = 1`npm.max_spare_servers = 3"
$NginxContent = "worker_processes auto;`npid /run/nginx.pid;`nevents { worker_connections 1024; }`nhttp {`n    include /etc/nginx/mime.types;`n    sendfile on;`n    server {`n        listen 80;`n        root /var/www/html/public;`n        index index.php;`n        location / { try_files `$uri `$uri/ /index.php?`$query_string; }`n        location ~ \.php$ {`n            fastcgi_pass unix:/run/php-fpm.sock;`n            include fastcgi_params;`n            fastcgi_param SCRIPT_FILENAME `$document_root`$fastcgi_script_name;`n        }`n    }`n}"
$SupervisorContent = "[supervisord]`nnodaemon=true`nuser=root`nlogfile=/var/log/supervisord.log`n[program:php-fpm]`ncommand=php-fpm -F`nautostart=true`nautorestart=true`n[program:nginx]`ncommand=nginx -g 'daemon off;'`nautostart=true`nautorestart=true"
$DockerfileContent = "FROM php:8.5-fpm-alpine AS base`nRUN apk add --no-cache icu-dev libpq-dev libzip-dev zip unzip postgresql-client nginx supervisor autoconf build-base`nRUN docker-php-ext-install -j`$(nproc) bcmath intl pdo_pgsql zip && pecl install redis && docker-php-ext-enable redis`nCOPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer`nRUN rm -rf /usr/local/etc/php-fpm.d/*.conf`nCOPY php-fpm.conf /usr/local/etc/php-fpm.conf`nCOPY nginx.conf /etc/nginx/nginx.conf`nRUN mkdir -p /run /var/log/nginx /var/log/supervisor && chown -R www-data:www-data /run /var/log/nginx`nWORKDIR /var/www/html`nCOPY --chown=www-data:www-data . /var/www/html`nRUN mkdir -p storage/logs storage/framework/views storage/framework/cache bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache`nCOPY supervisord.conf /etc/supervisord.conf`nCMD [`"/usr/bin/supervisord`", `"-c`", `"/etc/supervisord.conf`"]"

$ComposeContent = @"
services:
  api:
    build: { context: ./iartnet-api, dockerfile: Dockerfile }
    container_name: iartnet-api-${Environment}
    environment: { APP_ENV: testing, DB_HOST: postgres, DB_DATABASE: iartnet1, DB_USERNAME: postgres, DB_PASSWORD: admin, SESSION_DRIVER: redis, REDIS_HOST: redis }
    ports: ["8000:80"]
    depends_on: { postgres: { condition: service_healthy }, redis: { condition: service_healthy } }
    networks: [iartnet-network]
  postgres:
    image: postgres:16-alpine
    container_name: iartnet-db-${Environment}
    environment: { POSTGRES_DB: postgres, POSTGRES_USER: postgres, POSTGRES_PASSWORD: admin }
    healthcheck: { test: ["CMD-SHELL", "pg_isready -U postgres"], interval: 5s }
    networks: [iartnet-network]
    volumes: ["postgres_data_${Environment}:/var/lib/postgresql/data"]
  redis:
    image: redis:7-alpine
    container_name: iartnet-redis-${Environment}
    healthcheck: { test: ["CMD", "redis-cli", "ping"], interval: 5s }
    networks: [iartnet-network]
networks:
  iartnet-network: { driver: bridge }
volumes:
  postgres_data_${Environment}: { driver: local }
"@

# --- 4. SCRITTURA FILE DETERMINISTICA (UTF-8 NO BOM) ---
Write-Log "Fase 4: Scrittura configurazioni atomiche..."
if (Test-Path $ApiTarget) { Remove-Item -Recurse -Force $ApiTarget }
New-Item -ItemType Directory -Path $ApiTarget -Force | Out-Null
Copy-Item -Recurse -Force "$LocalTempBuild\*" $ApiTarget

function Write-UnixFile { param($Path, $Str) [System.IO.File]::WriteAllText($Path, $Str.Replace("`r`n", "`n"), $Utf8NoBom) }
Write-UnixFile (Join-Path $ApiTarget "Dockerfile") $DockerfileContent
Write-UnixFile (Join-Path $ApiTarget "php-fpm.conf") $PhpMainConfig
Write-UnixFile (Join-Path $ApiTarget "nginx.conf") $NginxContent
Write-UnixFile (Join-Path $ApiTarget "supervisord.conf") $SupervisorContent
Write-UnixFile (Join-Path $EnvDir "docker-compose.yml") $ComposeContent

# --- 5. DEPLOY E RESTORE DATABASE ---
cd $EnvDir
Write-Log "Fase 5: Reset Ambiente e Deploy..."
docker compose down -v
docker compose up -d --build

Write-Log "Attesa database online..."
while ((docker inspect --format='{{.State.Health.Status}}' iartnet-db-${Environment}) -ne "healthy") { Start-Sleep -s 2 }

if (Test-Path $SqlDumpPath) {
    Write-Log "Fase 6: Restore Database e Patch Strutturale UUID..."
    $CleanSql = (Get-Content $SqlDumpPath -Raw).Replace("LOCALE = 'Italian_Italy.1252'", "").Replace("DROP DATABASE iartnet1;", "")
    $CleanSql | docker exec -i iartnet-db-${Environment} psql -U postgres
    
    # PATCH CHIRURGICA: Rimozione factory numerica e conversione UUID
    $PatchSql = "ALTER TABLE iartnet_master.mirror_instances ALTER COLUMN id DROP DEFAULT; ALTER TABLE iartnet_master.mirror_instances ALTER COLUMN id TYPE uuid USING (gen_random_uuid());"
    docker exec -i iartnet-db-${Environment} psql -U postgres -d iartnet1 -c $PatchSql
}

# --- 6. FINALIZZAZIONE APPLICATIVA ---
if ((docker inspect --format='{{.State.Status}}' iartnet-api-${Environment}) -eq "running") {
    Write-Log "Fase 7: Finalizzazione permessi e stati..." -Col Green
    $DefaultEnv = "APP_NAME=IARTNET`nAPP_ENV=local`nAPP_KEY=`nDB_CONNECTION=pgsql`nDB_HOST=postgres`nDB_PORT=5432`nDB_DATABASE=iartnet1`nDB_USERNAME=postgres`nDB_PASSWORD=admin`nSESSION_DRIVER=redis`nREDIS_HOST=redis"
    [System.IO.File]::WriteAllText(".env", $DefaultEnv, $Utf8NoBom)
    
    docker cp .env iartnet-api-${Environment}:/var/www/html/.env
    docker exec iartnet-api-${Environment} php artisan key:generate --force
    docker exec iartnet-api-${Environment} chown -R www-data:www-data storage bootstrap/cache
    docker exec iartnet-api-${Environment} chmod -R 775 storage bootstrap/cache
    docker exec iartnet-api-${Environment} php artisan config:clear
    docker exec iartnet-redis-${Environment} redis-cli flushall
    
    Write-Log "SISTEMA ONLINE: http://localhost:8000" -Col Green
}