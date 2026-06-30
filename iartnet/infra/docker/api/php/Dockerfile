# file: /opt/Ingestion/iartnet/infra/docker/api/php/Dockerfile
# syntax=docker/dockerfile:1.6

ARG APP_ENV=local

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* pnpm-lock.yaml* yarn.lock* ./
RUN \
  if [ -f package-lock.json ]; then npm ci; \
  elif [ -f pnpm-lock.yaml ]; then corepack enable && pnpm i --frozen-lockfile; \
  elif [ -f yarn.lock ]; then yarn install --frozen-lockfile; \
  else npm install; fi
COPY . .
RUN npm run build

FROM php:8.4-fpm-bookworm AS app
WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl ca-certificates \
      libpq-dev \
      libzip-dev \
      libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.1 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-iartnet.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY . .
RUN set -eux; \
    attempt=1; \
    until COMPOSER_MAX_PARALLEL_HTTP=4 composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist; do \
        if [ "$attempt" -ge 3 ]; then \
            echo "Composer install failed after ${attempt} attempts" >&2; \
            exit 1; \
        fi; \
        delay=$((attempt * 10)); \
        echo "Composer install attempt ${attempt} failed; retrying in ${delay}s..." >&2; \
        sleep "$delay"; \
        attempt=$((attempt + 1)); \
    done

# Copia tutto il public generato dallo stage assets, non solo public/build.
# Questo include anche app statiche custom come public/stories-editor.
COPY --from=assets /app/public ./public

# Crea una copia master degli asset pubblici fuori da /var/www/html/public.
# /var/www/html/public viene mascherata dal volume Docker api_public in STG,
# quindi l'entrypoint userà questa copia per riallineare il volume pubblico.
RUN mkdir -p /opt/iartnet-public \
  && cp -a public/. /opt/iartnet-public/

# Fix permessi e directory Tinker/Psysh
RUN chown -R www-data:www-data /var/www/html /opt/iartnet-public \
  && mkdir -p storage bootstrap/cache /var/www/.config/psysh \
  && chown -R www-data:www-data storage bootstrap/cache /var/www/.config

USER www-data
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
