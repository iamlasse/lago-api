# Deploying api-laravel

The Laravel port ships as a drop-in replacement for the Rails `api`
container (`getlago/api`): same port (3000), same `/health` and `/ready`
endpoints, same `LAGO_*`/`SIDEKIQ_*`/`SECRET_KEY_BASE` environment names, and
the database is built by the same contract — the frozen schema loaded from
Rails' `db/structure.sql` (`php artisan migrate --force` runs the loader;
there is exactly one migration).

## Image

`api-laravel/Dockerfile` — multi-stage:

1. **deps** — `composer:2`, `composer install --no-dev` (pint/pest/larastan
   stay out of the runtime), optimized autoloader, scripts skipped.
2. **runtime** — `php:8.4-fpm-alpine` + nginx + supervisor. Extensions:
   `bcmath`, `pdo_pgsql`, `intl`, `zip`, `opcache` (enabled, timestamps
   validation off — the image is immutable), `redis` (phpredis, matching
   `REDIS_CLIENT=phpredis`).

Runs unprivileged as `www-data`; nginx master on :3000, php-fpm pool on
127.0.0.1:9000 (`clear_env=no` so runtime `env()` reads — the `SIDEKIQ_*`
queue-split flags — survive under fpm). Entrypoint
`docker/entrypoint.sh [web|migrate|worker|clock]`:

- warms `config:cache` + `route:cache` on every start (idempotent);
- runs `php artisan migrate --force` unless `MIGRATE_ON_START=false`
  (the compose overlay's one-shot `api-laravel-migrate` owns it);
- then supervises php-fpm + nginx (`web`), runs the queue consumer
  (`worker`), or the scheduler (`clock`).

## Monorepo topology

```bash
cd /path/to/lago   # monorepo root
docker compose -f docker-compose.yml -f docker-compose.laravel.yml up api-laravel
```

brings up, in dependency order:

| Service | Role | Rails analogue |
|---|---|---|
| `db`, `redis`, `pdf` | reused from the base compose file | same |
| `api-laravel-migrate` | one-shot frozen-schema loader | `migrate` |
| `api-laravel` (:3000) | nginx+php-fpm API | `api` |
| `api-laravel-worker` | queue consumer, all 17 queues | `api-worker` |
| `api-laravel-clock` | `schedule:work` (clock.rb cadence) | `api-clock` |

The overlay's `x-laravel-backend-env` anchor mirrors the base file's
`x-backend-environment` 1:1 — same `${POSTGRES_*}`/`${REDIS_*}`/`LAGO_*`
interpolation — so a root `.env` feeds both stacks identically. Two names
are translated because Laravel's config reads different keys:

| Rails name | Laravel name | Note |
|---|---|---|
| `DATABASE_URL` | `DB_URL` | `config/database.php` `url` key; the `?search_path=` query arg is dropped (Laravel's pgsql connector defaults to `search_path=public`, matching the anchor's `POSTGRES_SCHEMA`) |
| `RAILS_ENV=production` | `APP_ENV=production` | |

Dedicated Sidekiq-style workers can be carved out by setting the split flags
on the worker service — the port routes jobs exactly like Rails
(`SIDEKIQ_EVENTS=true` → dedicated `events` queue, `SIDEKIQ_PDFS=true` →
`pdfs`, `SIDEKIQ_BILLING=true` → `billing`, plus `SIDEKIQ_CLOCK/WEBHOOK/
ALERTS/PROVIDERS/PAYMENTS/WALLETS`). Queue names are Rails': `high_priority,
default, mailers, clock, providers, webhook, invoices, integrations,
low_priority, long_running, events, billing, pdfs, payments, alerts,
analytics, wallets`.

## Environment reference

`api-laravel/.env.example` documents every variable the app reads. The
production-critical set:

| Variable | Purpose |
|---|---|
| `DB_URL` (or discrete `DB_*`) | Postgres DSN; the frozen-schema loader builds the entire schema on first migrate |
| `REDIS_URL`, `LAGO_REDIS_CACHE_URL` | queue/cache Redis (`LAGO_REDIS_CACHE_URL` mirrors Rails' cache connection) |
| `SECRET_KEY_BASE` | HS256 key for API-token JWTs (`App\Support\Utils\AuthToken`) — must be ≥ 32 bytes, same value as Rails |
| `APP_KEY` | Laravel's own encryption key (`php artisan key:generate`) |
| `QUEUE_CONNECTION=redis` | the overlay pins this; jobs are Horizon/queue:work consumers |
| `LIGHTHOUSE_QUERY_CACHE_MODE=opcache` | parsed-GraphQL-query cache; the app cache refuses to unserialize objects, so this must stay `opcache` in production |
| `LAGO_PDF_URL` | Gotenberg (`http://pdf:3000` in the compose topology) |
| `LAGO_RSA_PRIVATE_KEY_PATH` | RS256 key for webhook signatures (Rails: `LAGO_RSA_PRIVATE_KEY`) |
| `LAGO_LICENSE` | premium feature gate, same value as Rails |
| `MIGRATE_ON_START` | `true` (default) for bare `docker run`; `false` in the overlay, where `api-laravel-migrate` owns migrations |

## Worker topology

`docker/entrypoint.sh worker` starts Horizon when `config/horizon.php` is
published. **Today it is not** (Horizon is installed but `horizon:install`
was never run in the port), and Horizon's vendor default consumes only the
`default` queue — which would strand the other 16. Until the config lands,
the entrypoint falls back to a `queue:work redis` process covering the full
queue surface with `--tries=1` (the port's `max_retries 0` semantics) and
`--max-time=3600` process recycling.

## Swap test (drop-in parity walkthrough)

Run the same walkthrough against Rails and Laravel and diff responses.

1. **Boot the Rails stack** (baseline):
   ```bash
   docker compose up -d db redis pdf
   docker compose up migrate && docker compose up -d api
   curl -s localhost:3000/health   # {"version":...,"message":"Success"}
   curl -s localhost:3000/ready    # {"status":"ok"}
   ```
2. **Boot the Laravel stack on the same db/redis**:
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.laravel.yml up \
     api-laravel-migrate && \
   docker compose -f docker-compose.yml -f docker-compose.laravel.yml up -d api-laravel
   curl -s localhost:3000/health   # same shape: version, github_url, "Success"
   curl -s localhost:3000/ready    # {"status":"ok"}
   ```
3. **Behavioral parity checklist** (against the same DB — stop the Rails
   containers first to free :3000):
   - `POST /api/v1/customers` create → identical JSON envelope (contract
     golden tests cover this: `tests/Contract/`).
   - `POST /graphql` with the same mutation + `Authorization: Bearer` API key
     → identical response (the schema is served verbatim from `graphql/`).
   - `GET /api/v1/invoices` pagination shape, error envelopes (404/422).
   - Dispatch an invoice finalization and confirm the job lands on the same
     queue name and produces the same webhook payload signature.
4. **Endpoint-level diffing** at scale: `php artisan inventory:check`
   verifies the route/GraphQL/serializer/job/service/table inventories
   against the Rails checkout (`LAGO_RAILS_PATH`).

## Local smoke test

```bash
docker build -t lago-api-laravel ./api-laravel
docker run --rm -p 3000:3000 \
  -e DB_URL=postgresql://postgres:postgres@host.docker.internal:5433/lago_test_ci \
  -e REDIS_URL=redis://host.docker.internal:6379 \
  -e SECRET_KEY_BASE=0123456789abcdef0123456789abcdef0123456789abcdef \
  lago-api-laravel
curl -s localhost:3000/health
```

## CI

`.github/workflows/laravel.yml` gates every `api-laravel/**` change:
pint (style) → larastan (report-only for now) → the full Pest suite on
`getlago/postgres-partman:15.0-alpine` (frozen-schema loader runs in-pipeline,
test db `lago_test_ci`) + the frozen-schema catalog diff (psql inline
equivalent of `scripts/verify-frozen-schema.sh`) → inventory coverage check
(report-only until the ledger closes).

## Known gaps / deviations

- **`route:cache` fails — duplicate auto-generated route names** (found by
  this containerization): the `Route::prefix('x')->as('x:')` groups in
  `routes/api.php` (billable_metrics, taxes, webhook_endpoints, …) give both
  the empty-URI index and create routes the same computed name (e.g. two
  routes named `billable_metrics:`), so `php artisan route:cache` aborts with
  "Unable to prepare route … Another route has already been assigned name".
  The entrypoint logs a warning and serves uncached routes (boot is
  unaffected; uncached route matching is functionally identical, just slower).
  Fix app-side: give the member routes explicit `->name('index')` /
  `->name('create')` names (or drop the trailing colon in the group `as()`).
- **Horizon config unpublished** — worker falls back to `queue:work` across
  all queues (above). Publish + tune `config/horizon.php` (queue sets per
  `SIDEKIQ_*` flags, supervisor sizing) as an app-side follow-up.
- **`pg_partman` in CI** — the frozen schema creates the `pg_partman`
  extension; CI uses `getlago/postgres-partman` so it loads, same as the dev
  container.
- **`LAGO_VERSION`** — the Rails image bakes `LAGO_VERSION`; the port has no
  copy yet, so `/health` reports the `APP_ENV` string instead of a release
  tag (port of `LagoUtils::Version`). Copy the file into the build context
  (one `COPY LAGO_VERSION .` line) once release tagging starts.
- **Image size** — tests are excluded from the runtime image via
  `.dockerignore`; if they should ship for in-container verification, drop
  the `tests/` and dev-deps exclusions.
- **Larastan / inventory gates** — both run report-only in CI until their
  existing findings clear (larastan ~2.8k findings at level 5; inventory
  ledger has open rows). They are wired to flip to blocking with one
  `continue-on-error` toggle each.
