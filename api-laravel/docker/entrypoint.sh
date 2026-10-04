#!/bin/sh
# api-laravel container entrypoint.
#
# Usage: entrypoint.sh [web|migrate|worker|clock]   (default: web)
#
# Every mode first warms the config/route caches (idempotent — safe on every
# container start; config:cache rewrites bootstrap/cache/config.php).
# `web` then supervises php-fpm+nginx, `migrate` runs the frozen-schema
# loader and exits, `worker` runs the queue consumer, `clock` the scheduler.
#
# MIGRATE_ON_START (default "true"): each mode runs `php artisan migrate
# --force` before starting its process. In the compose overlay the one-shot
# api-laravel-migrate service owns the migration (same topology as the Rails
# `migrate` service) and the long-running services set MIGRATE_ON_START=false
# so three containers never migrate concurrently.
set -eu

MODE="${1:-web}"
APP_DIR="/var/www/html"

cd "$APP_DIR"

echo "[entrypoint] warming caches (config:cache, route:cache)..."
php artisan config:cache
# KNOWN APP GAP (see DEPLOY.md): route:cache currently fails — the
# `Route::prefix('x')->as('x:')` groups in routes/api.php auto-generate
# duplicate names for their empty-URI index/create pairs (e.g. two routes
# named `billable_metrics:`), which Laravel refuses to serialize. Serve
# uncached routes until the route table gets explicit per-action names.
if ! php artisan route:cache 2>&1; then
    echo "[entrypoint] WARNING: route:cache failed (duplicate auto-generated route names in routes/api.php) — serving uncached routes." >&2
fi

run_migrations() {
    echo "[entrypoint] running migrations (frozen-schema loader)..."
    php artisan migrate --force
}

case "$MODE" in
    migrate)
        run_migrations
        echo "[entrypoint] migrations complete."
        ;;

    web)
        if [ "${MIGRATE_ON_START:-true}" = "true" ]; then run_migrations; fi
        echo "[entrypoint] supervising php-fpm + nginx on :3000"
        exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;

    worker)
        if [ "${MIGRATE_ON_START:-true}" = "true" ]; then run_migrations; fi
        if [ -f config/horizon.php ]; then
            # Published Horizon config — queue sets come from the SIDEKIQ_*
            # split flags as configured there.
            echo "[entrypoint] starting Horizon"
            exec php artisan horizon
        fi
        # Horizon's config/horizon.php is not published in the port yet; the
        # vendor default only consumes the `default` queue, which would
        # strand the other 16 Lago queues. Fall back to a queue:work process
        # covering the full Sidekiq queue surface (names mirror Rails').
        echo "[entrypoint] starting queue:work redis (Horizon config not published)"
        exec php artisan queue:work redis \
            --queue=high_priority,default,mailers,clock,providers,webhook,invoices,integrations,low_priority,long_running,events,billing,pdfs,payments,alerts,analytics,wallets \
            --tries=1 \
            --sleep=1 \
            --timeout=120 \
            --max-time=3600 \
            --backoff=5
        ;;

    clock)
        if [ "${MIGRATE_ON_START:-true}" = "true" ]; then run_migrations; fi
        # schedule:work runs the scheduler every minute in the foreground —
        # the port of Rails' clock.rb cadence (routes/console.php).
        echo "[entrypoint] starting scheduler"
        exec php artisan schedule:work
        ;;

    *)
        echo "[entrypoint] unknown mode: $MODE (expected web|migrate|worker|clock)" >&2
        exit 64
        ;;
esac
