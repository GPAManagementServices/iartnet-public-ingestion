#!/usr/bin/env sh
set -eu

if [ ! -f ".env" ] && [ -f ".env.example" ]; then
  cp .env.example .env
fi

if [ -f ".env" ] && ! grep -qE '^APP_KEY=base64:' .env; then
  echo "[entrypoint] APP_KEY missing -> generating..."
  php artisan key:generate --force || true
fi

echo "[Boot] Sync public assets into mounted public volume..."
if [ -d "/opt/iartnet-public" ]; then
  mkdir -p /var/www/html/public
  cp -a /opt/iartnet-public/. /var/www/html/public/
fi

php artisan storage:link >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true
php artisan cache:clear  >/dev/null 2>&1 || true

exec "$@"
