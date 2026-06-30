#!/usr/bin/env sh
set -eu

echo "[Boot] Fase 1: Inizializzazione Laravel Lifecycle..."
if [ ! -f ".env" ] && [ -f ".env.example" ]; then
  cp .env.example .env
fi

if [ -f ".env" ] && ! grep -qE '^APP_KEY=base64:' .env; then
    echo "[Boot] APP_KEY mancante -> generazione in corso..."
    php artisan key:generate --force || true
fi

echo "[Boot] Fase 2: Pubblicazione Asset e Pulizia Cache..."

echo "[Boot] Sync public assets into mounted public volume..."
if [ -d "/opt/iartnet-public" ]; then
  mkdir -p /var/www/html/public
  cp -a /opt/iartnet-public/. /var/www/html/public/
fi

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan livewire:publish --assets >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear  >/dev/null 2>&1 || true
php artisan view:clear   >/dev/null 2>&1 || true

echo "[Boot] Fase 3: Hardening Volumi e Permessi I/O..."
mkdir -p /var/www/html/storage/app/ingestion
mkdir -p /var/www/html/storage/app/public
mkdir -p /var/www/html/bootstrap/cache

# Applicato per ultimo per includere eventuali file appena generati dai comandi artisan
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "[Boot] Container pronto. Handover al processo primario."
exec "$@"
