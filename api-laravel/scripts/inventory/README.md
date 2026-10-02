# Inventory generators

Machine-generated inventories of the Rails API (`getlago/lago-api`). Nothing in
the coverage ledger is tracked from memory: every work item originates from one
of these artifacts, committed under `tests/inventory/` (format documented in
[`tests/inventory/FORMAT.md`](../../tests/inventory/FORMAT.md)).

## Sources

| Artifact | Source | Generator |
|---|---|---|
| `tests/inventory/graphql.json` | `schema.json` (introspection) | `gen_graphql.php` |
| `tests/inventory/serializers.json` | `app/serializers/**/*.rb` | `gen_serializers.php` |
| `tests/inventory/jobs.json` | `app/jobs/**/*.rb` (+ `queue_as`, `unique`, `retry_on` stanzas) | `gen_jobs.php` |
| `tests/inventory/services.json` | `app/services/**/*.rb` | `gen_services.php` |
| `tests/inventory/tables.json` | `db/structure.sql` CREATE TABLE blocks | `gen_tables.php` |
| `tests/inventory/rest.json` | **live route dump** — `gen_routes.rb` inside Rails | `gen_routes.rb` |
| `tests/inventory/rest.json` (interim) | **static parse** of `config/routes*.rb` | `gen_routes_from_source.php` |
| `tests/inventory/ledger.json` | join of all of the above with the `app/` scan | `gen-all.php` |

## Running

Default Rails checkout: `/tmp/lago-api-exploration`. Override with
`LAGO_RAILS_PATH=...` or `--rails-path=...` (env var / option works on every
generator and on `php artisan inventory:check`).

```bash
# everything (writes all artifacts + refreshes the ledger)
php scripts/inventory/gen-all.php

# individually
php scripts/inventory/gen_graphql.php
php scripts/inventory/gen_serializers.php
php scripts/inventory/gen_jobs.php
php scripts/inventory/gen_services.php
php scripts/inventory/gen_tables.php
php scripts/inventory/gen_routes_from_source.php   # PROVISIONAL — see below
```

## REST routes: static parsing is provisional by design

The plan is explicit: the REST inventory must come from a **live route dump**
(`bin/rails runner` over `Rails.application.routes`) — *never* from parsing
`routes.rb` statically. We cannot boot Rails in this environment yet, so
`gen_routes_from_source.php` produces a best-effort static inventory instead.
Consequences:

- every row carries `"provisional": true`, and the artifact itself has a
  top-level `provisional` flag plus a warning;
- environment-conditional routes (e.g. `Rails.env.local?` admin routes) are
  included, because the parser cannot evaluate the condition;
- the parser only understands the DSL subset these files use; anything it
  cannot interpret is silently skipped (another reason not to trust it).

**Replacing it:** run `gen_routes.rb` inside the Rails repo (its docker image
is fine) and commit the dump:

```bash
cd /path/to/lago-api
docker compose run --rm api bash -c \
  'bundle exec rails runner /path/to/api-laravel/scripts/inventory/gen_routes.rb' \
  > /path/to/api-laravel/tests/inventory/rest_dump.json
```

Then regenerate `rest.json` from the dump (a small importer; until it exists,
keep the static artifact but treat every rest row in the ledger as unverified)
and re-run `php artisan inventory:check` — it fails on any drift between the
committed artifacts and a fresh regeneration.

## Idempotence

Generators are deterministic: rows are sorted by id, JSON is canonical
(pretty, unescaped slashes, trailing newline), and no timestamps are embedded,
so re-running on an unchanged Rails checkout must produce byte-identical
artifacts. `inventory:check` enforces exactly that.
