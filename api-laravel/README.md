# api-laravel

A drop-in Laravel replacement for the Lago Rails API container
(`getlago/api`). Same port (3000), same `/health` and `/ready` endpoints,
same `LAGO_*`/`SIDEKIQ_*`/`SECRET_KEY_BASE` environment names, same queue
names, and the same database — the Rails stack can be swapped out and this
booted against its database (see the [swap test walkthrough](DEPLOY.md#swap-test-drop-in-parity-walkthrough)).

## Architecture

The schema is **frozen**: there is exactly one migration, the loader that
builds the entire database from Rails' `db/structure.sql` — no Laravel
migrations will ever diverge from the Rails catalog. On top of it the port
re-implements the Rails surface three ways: REST v1 + v2, the GraphQL schema
(served verbatim from `graphql/`, so responses match Rails field-for-field),
and the background jobs that land on Rails' 17 Sidekiq queue names. Correctness
is pinned by **contract-golden testing**: request/response pairs are captured
from the real Rails API against seeded fixtures, then replayed against this
port, which must answer byte-identically (modulo an explicit Normalizer
allowlist for minted ids and tokens).

Coverage is tracked row-by-row, not from memory: machine-generated inventories
of the Rails API (REST routes, GraphQL fields, serializers, services, jobs,
tables) live in [`tests/inventory/`](tests/inventory/FORMAT.md) and every work
item is a row in their ledger with a status. The current numbers are generated
into [`docs/PORT_STATUS.md`](docs/PORT_STATUS.md).

## Quickstart

Docker (alongside the rest of the monorepo):

```bash
cd /path/to/lago   # monorepo root
docker compose -f docker-compose.yml -f docker-compose.laravel.yml up \
  api-laravel-migrate api-laravel
```

This brings up the one-shot frozen-schema loader, the API on :3000, the queue
worker, and the scheduler. Details: [`DEPLOY.md`](DEPLOY.md).

Local:

```bash
composer install
cp .env.example .env          # set DB_*/REDIS_URL/SECRET_KEY_BASE (see .env.example)
php artisan key:generate
php artisan migrate --force   # loads the frozen schema
php artisan serve
```

## Test gates

Every `api-laravel/**` change goes through (`.github/workflows/laravel.yml`):

| Gate | Command | Notes |
| --- | --- | --- |
| Style | `vendor/bin/pint --dirty` | |
| Static analysis | `vendor/bin/phpstan` | larastan, report-only until findings clear |
| Suite | `vendor/bin/pest` | runs on the frozen schema (`getlago/postgres-partman:15` in CI) |
| Inventory | `php artisan inventory:check` | artifacts fresh, ledger complete; `--milestone=m1` gates the M1 scope |
| Contract | `PAO_DISABLE=1 DB_DATABASE=<disposable> ./vendor/bin/pest tests/Contract` | replays the Rails goldens |

## Where to read next

- [`DEPLOY.md`](DEPLOY.md) — image, compose topology, environment reference, known gaps.
- [`docs/PORT_STATUS.md`](docs/PORT_STATUS.md) — generated coverage numbers and the categorized backlog (regenerate with `php scripts/port-status.php`).
- [`scripts/contract/README.md`](scripts/contract/README.md) — how Rails goldens are captured and replayed, and the current findings.
- [`tests/inventory/FORMAT.md`](tests/inventory/FORMAT.md) — the inventory/ledger format and the milestone gate rules.
