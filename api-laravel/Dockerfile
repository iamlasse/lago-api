# api-laravel production image.
#
# Drop-in for the Rails `api` container (getlago/api): multi-stage build,
# nginx + php-fpm under supervisor, listening on port 3000, same /health and
# /ready endpoints. Startup is `docker/entrypoint.sh web`: config/route cache
# warming (idempotent) + `php artisan migrate --force` (the frozen-schema
# loader) + supervisord.
#
# Build (from api-laravel/):   docker build -t lago-api-laravel .
# Run:                          docker run --rm -p 3000:3000 lago-api-laravel
# Modes:                        web (default) | migrate | worker | clock

# ---------------------------------------------------------------------------
# Stage 1 — composer dependencies (no dev tools: pint/pest/larastan stay out
# of the runtime image).
# ---------------------------------------------------------------------------
FROM composer:2 AS deps

WORKDIR /app

# Composer platform check runs before the source exists, so copy only what
# install needs. Scripts are skipped: post-autoload-dump runs artisan, which
# requires the full source; Laravel rebuilds the package manifest on demand
# at runtime.
COPY composer.json composer.lock ./
# ext-pcntl is in the lock's platform requirements (Horizon needs it for
# signal handling); the composer base image ships without it, so the runtime
# stage compiles pcntl and this stage ignores the platform check.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-pcntl

# ---------------------------------------------------------------------------
# Stage 2 — runtime: php:8.4-fpm-alpine + nginx + supervisor.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# Runtime libs: icu (intl), libzip (zip), libpq (pdo_pgsql runtime dep —
# postgresql-dev is build-only), nginx, supervisor, curl (healthcheck).
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        icu-libs \
        libzip \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        ${PHPIZE_DEPS} \
        icu-dev \
        libzip-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        opcache \
        pcntl \
        pdo_pgsql \
        intl \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# PHP production overrides (opcache sealed to the immutable image, UTC, bcmath
# is compiled in — the BcNumeric cast runs billing math at scale 15).
COPY docker/php.ini $PHP_INI_DIR/conf.d/99-lago.ini

# php-fpm pool overrides (clear_env=no so runtime env() reads — the SIDEKIQ_*
# queue-split flags — survive under fpm; see docker/php-fpm.conf).
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-lago.conf

# nginx: unprivileged master on :3000 (drop-in for the Rails api container).
COPY docker/nginx.conf /etc/nginx/nginx.conf

# supervisor: php-fpm + nginx under one init.
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# Application source + vendor (deps stage). .dockerignore excludes local
# state (.env, tests stay in the image for now — see DEPLOY.md gaps).
COPY --from=deps /app/vendor vendor/
COPY . .

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    REDIS_CLIENT=phpredis \
    MIGRATE_ON_START=true \
    PORT=3000

# Unprivileged runtime, mirroring the intent of the Rails image: www-data
# owns storage/ (app files) and bootstrap/cache (config/route/view caches,
# Lighthouse opcache query cache); nginx/fpm/supervisor pids+temps go to /tmp.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p /tmp/nginx_client_body /tmp/nginx_proxy /tmp/nginx_fastcgi \
                /tmp/nginx_uwsgi /tmp/nginx_scgi \
    && chown -R www-data:www-data /tmp

USER www-data

EXPOSE 3000

ENTRYPOINT ["./docker/entrypoint.sh"]
CMD ["web"]
