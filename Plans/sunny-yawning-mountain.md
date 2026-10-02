# Port Lago Rails API → Laravel 13 (drop-in replacement)

## Context

Lago is an open-source usage-based billing engine. The monorepo at
`/Users/lasselarsen/Projects/lago` contains the Rails API (`api/` submodule,
getlago/lago-api, ~155k LOC Ruby: 113 models, 97 REST controllers, 944-file
GraphQL schema, 269 jobs, 717 migrations, 2,555 specs), the Vue admin frontend
(`front/`), a Go events-processor writing to ClickHouse, and Go/Kafka
connectors.

**Goal:** replace the Rails API with a Laravel 13 app in a new
`api-laravel/` directory. The Vue frontend and Go services stay untouched, so
the Laravel app must be a **drop-in replacement**: same REST v1 API (mirrored
at v2), same admin GraphQL API, same frozen Postgres schema.

**Locked decisions:**
- Drop-in fidelity — exact API contracts, schema taken verbatim from Rails' `db/structure.sql`
- Phased vertical slices; each milestone usable end-to-end against the real frontend
- Milestone 1 = auth + organizations + customers + billable metrics + plans + subscriptions + basic invoicing + webhooks (M1 scope)
- **Coverage ledger:** every work item comes from a machine-generated inventory of the Rails codebase; nothing planned from memory; `inventory:check --milestone=mN` is the mechanical milestone gate
- **Tests travel with every piece:** no inventory row is "ported" without its test; ported RSpec scenarios or written-first specs, same PR as the code; contract tests diff Laravel responses against goldens captured from Rails

Exploration source: `/tmp/lago-api-exploration` (shallow clone of api submodule HEAD).
The real contract files: `db/structure.sql` (16,473 lines — **the schema source of
truth**, there is no schema.rb) and `schema.graphql` / `schema.json` (16,794 lines).

---

## M0 — Skeleton

### Packages (composer)
`laravel/framework ^13.0` (PHP 8.4), `nuwave/lighthouse` (⚠️ **T0.1 spike: pin
L13-compatible release; fallback = Laravel 12**, nothing else in the plan needs
L13), `mll-lab/graphql-php-scalars`, `laravel/horizon`, `predis/predis`,
`firebase/php-jwt`, `sentry/sentry-laravel`; dev: pest (+laravel plugin),
larastan, pint, mockery. Explicitly rejected: Sanctum (auth is UUID Bearer +
JWT), spatie/activitylog (must write Rails' frozen `versions` table → small
in-house `Versionable` trait), any PDF package (Gotenberg HTTP call).

### Frozen schema
- `scripts/freeze-schema.sh` copies `structure.sql` verbatim into
  `api-laravel/database/frozen/`, strips only (each documented in
  `EXCLUSIONS.md`): Rails bookkeeping (`schema_migrations`, `ar_internal_metadata`
  + their COPY data), and the partman block (`CREATE SCHEMA partman` at
  structure.sql:1485, `pg_partman` extension, partitioned `enriched_events` +
  template + part_config — events are M2+; re-enable verbatim then).
  Everything else byte-identical: all ~46 native PG enums, indexes, FKs,
  `versions`, `active_storage_*`, jsonb quirks (`charges.properties DEFAULT '"{}"'`).
- Single migration `0001_…_load_frozen_schema.php` runs the SQL;
  `down()` refuses. M0 exit gate: `pg_dump` of the migrated Laravel DB diffs
  **clean** against the exclusion-documented structure.sql.
- PHP extensions in image: bcmath, pdo_pgsql, redis, intl, opcache.

### Structure (Rails → Laravel mapping)
```
api-laravel/
├── app/
│   ├── Enums/            # PG enums + integer enums (InvoiceStatus, ChargeModel, AggregationType{0,1,2,3,5,6,7} — 4 deleted, never renumber)
│   ├── GraphQL/{Scalar,Union,Interface,Middleware,Mutations}
│   ├── Http/Controllers/Api/{V1,V2}/   # mirrors app/controllers/api/v1/*
│   ├── Http/Middleware/  # AuthenticateApiKey, AuthorizeApiKey, BetaHeader(v2), RequestContext
│   ├── Models/Concerns/  # HasUuid, BaseModel, Discardable(SoftDeletes on deleted_at), BelongsToOrganization, Sequenced, Versionable
│   ├── Serializers/{Base,V1}/          # 1:1 ports of app/serializers — NOT Laravel API Resources (see below)
│   ├── Services/         # mirrors app/services/* namespace exactly (Invoices/CalculateFeesService ↔ Invoices::CalculateFeesService)
│   ├── Jobs/             # mirrors app/jobs/* incl. Clock/
│   ├── Queries/, Validators/, Support/(CurrentContext, Utils\AuthToken), Exceptions/
├── graphql/              # schema.graphql served verbatim (frozen contract)
├── database/{migrations,factories,frozen}/
├── routes/api.php        # generated inventory-driven from Rails route table
├── scripts/{inventory,contract}/
├── tests/{Feature,Unit,GraphQL,Contract,inventory}/
├── docker/ + Dockerfile  # nginx + php-fpm on port 3000 (drop-in; no Octane for M0), supervisor
└── docker-compose.laravel.yml  # overlay: api-laravel + api-laravel-worker(Horizon) + api-laravel-clock
```

### Eloquent base patterns
- `HasUuid`: v4 UUID string PKs (`$incrementing=false`); timestamps
  `timestamp(6) without tz` UTC (`dateFormat 'Y-m-d H:i:s.u'`, app+DB tz UTC).
- `Sequenced` trait = verbatim port of `app/models/concerns/sequenced.rb`
  (verified): inside open transaction → `SET LOCAL lock_timeout='10s'`,
  `pg_advisory_xact_lock(hashtext('<class>_lock'))` (hash computed in PG, never
  in PHP), `max(sequential_id)+1`, SQLSTATE 55P03 → retryable `SequenceException`.
- `BelongsToOrganization`: relation + default tenant scope in API contexts,
  opt-out for system jobs (mirrors Rails' controller-set `current_organization`).
- `BcNumeric` cast (bcmath, scale 15) for `numeric(40,15)` columns — never
  PHP floats in billing math; bigint cents → int.
- Integer enums as PHP backed enums; serializers output the string names.
- `Versionable` writes `versions` rows with Rails class names in `item_type`.

### Env & config
`config/lago.php` reads the **same LAGO_*/SIDEKIQ_* env names** as Rails so
compose files work unchanged (`DATABASE_URL` parsing, `SECRET_KEY_BASE` as JWT
secret, `LAGO_REDIS_CACHE_URL`, `LAGO_PDF_URL`, `LAGO_WEBHOOK_*`, queue-split
flags `SIDEKIQ_BILLING/EVENTS/PDFS/WEBHOOK/CLOCK/ALERTS` driving both job
`queue()` and Horizon supervisors). Queue names exactly as Sidekiq's:
high_priority, default, mailers, clock, providers, webhook, invoices,
integrations, low_priority, long_running, events, billing, pdfs, payments,
alerts, analytics, wallets. `max_retries 0` semantics → `$tries=1` default +
per-job backoff/retryUntil ports; uniqueness stanzas (`until_executed`,
lock_ttl) → custom job middleware (Laravel's `ShouldBeUnique` alone is
insufficient). Scheduler (`api-laravel-clock`) replicates clock.rb entries:
`SubscriptionsBillerJob` hourly :10, `ActivateSubscriptionsJob` /
`RefreshDraftInvoicesJob` every 5 min, finalize/overdue/retry hourly slots.
Endpoints: `GET /health`, `/ready`, `/graphql`, catch-all JSON 404.

---

## Coverage ledger (anti-flakiness core)

Generators in `scripts/inventory/` read the Rails repo (read-only) and write
committed YAML to `tests/inventory/`:

| Artifact | Source | Output |
|---|---|---|
| REST routes | live route dump (`bin/rails runner` over `Rails.application.routes`) — never parse routes.rb statically | inventory/rest.yaml |
| GraphQL ops | walk `schema.json` (introspection) + resolver file scan | inventory/graphql.yaml |
| Serializers / Jobs / Services / Tables | file scans + structure.sql parse | inventory/{serializers,jobs,services,tables}.yaml |

Row IDs: `rest:GET:/api/v1/customers/:external_id`, `gql:mutation:createCustomer`,
`job:BillSubscriptionJob`, `svc:Invoices.CalculateFeesService`,
`ser:V1.CustomerSerializer`, `table:customers`.

`ledger.yaml` per row: `source` (Rails path) → `laravel` (target class) →
`status: {code, test, contract}` → linked test files. **Tests travel with the
code:** `code: done` requires `test: done` in the same PR; tests carry the row
id as a Pest group (`->group('ledger:<row-id>')`) so coverage is checkable via
`pest --list-tests`.

`php artisan inventory:check --milestone=m1`: (1) regenerates inventories and
fails on drift, (2) verifies each row's Laravel class/route/queue exists,
(3) verifies test files exist and carry the row id, (4) milestone gate: all
scoped rows `done/done/pass` else exit 1. Wired into CI.

## Contract test harness

1. **Capture (Rails side, per scenario):** boot Rails with scratch DB → run a
   scenario script via `bin/rails runner` that seeds with Rails' own factories
   (deterministic ids, `travel_to` fixed clock), issues the scenario's HTTP
   calls in-process, writes parsed responses to `goldens/<scenario>/*.json` +
   manifest, then `pg_dump --data-only` → `goldens/<scenario>/fixture.sql`.
   Committed to the Laravel repo.
2. **Replay (Laravel side):** Pest `tests/Contract/` loads the same fixture.sql
   + rewinds the clock → replays requests → deep JSON compare (normalize only
   `Z` vs `+00:00` and float formatting on rate fields; volatile headers
   allowlisted; required headers asserted: `X-Lago-Endpoint-Status: beta` on
   v2, `x-lago-token`). Failure prints diff + ledger row id.
3. M1 scenarios: auth_org (incl. **Rails-minted JWT authenticating in Laravel**
   and vice versa), customers_crud, plans/metrics/charges CRUD,
   subscription create (advance + arrears), upgrade/downgrade,
   invoice finalize, invoice proration, one scenario per charge model
   (standard, graduated, package, percentage, volume, graduated_percentage,
   custom, dynamic).

---

## M1 — task order (dependency-ordered; S≤0.5d, M=1–2d, L=3–5d, XL=1–2w)

| # | Task | Key Rails sources → Laravel targets | Tests | Eff |
|---|---|---|---|---|
| 0 | **Spike: Lighthouse × L13** pin; fallback decision L12 | — | introspection smoke | S |
| 1 | REST auth: Bearer UUID → `api_keys.value`, Redis cache (1h TTL, expiry-aware), JSONB `{resource:[read\|write]}` perms, 401/403 envelopes, v2 beta header | `api/base_controller.rb`, `concerns/{api_errors,pagination,common}.rb`, `services/api_keys/cache_service.rb` → `Http/Middleware/*`, `Services/ApiKeys/CacheService` | spec/requests/api/base_controller_spec* | M |
| 2 | GraphQL auth: `Utils\AuthToken` (HS256, SECRET_KEY_BASE, 3h, `sub`), x-lago-organization switch, x-lago-token renewal (<1h), `LoginUser` (bcrypt via `password_verify` on `password_digest` — cross-language compatible) | `services/utils/auth_token.rb`, `concerns/authenticable_user.rb`, `mutations/login_user.rb` → `Support/Utils/AuthToken`, `GraphQL/Middleware/*` | login_user_spec, auth_token_spec, contract auth_org | M |
| 3 | Serializer base + pagination trait + error envelope (`{status,error,code,error_details}` with exact code pairs) | `serializers/{model,collection}_serializer.rb` → `Serializers/Base/*` | serializer specs + envelope unit tests | S |
| 4 | Organizations: GET/PUT `/api/v1/organizations`, grpc_token; GraphQL currentUser/organization/updateOrganization | `organizations_controller.rb`, `Organizations::UpdateService` | organizations_controller_spec | S–M |
| 5 | Customers: upsert-on-external_id semantics, sync response + async webhook, metadata subresource, nested reads | `customers_controller.rb`, `Customers::{Create,Update}Service`, `V1::CustomerSerializer` | customers_controller_spec + services specs | L |
| 6 | Billable metrics (full CRUD) + Taxes CRUD + coupons to the depth M1 totals need | `billable_metrics_controller.rb`, `taxes_controller.rb`, respective services | corresponding request + service specs | M |
| 7 | Plans + Charges + FixedCharges + Filters: nested writes, per-charge-model property validation (8 models) | `plans_controller.rb`, `Plans::*Service`, `Charges::*Service`, `app/validators/*` | plans specs + validator matrix | L |
| 8 | Subscriptions: `customer.with_lock` (SELECT…FOR UPDATE), upgrade/downgrade branches, today-start → activate + immediate invoice, `Subscriptions::DatesService` family, terminate | `subscriptions_controller.rb`, `Subscriptions::{Create,Update,Terminate}Service`, `DatesService` + variants | subscriptions specs (dates matrix highest-value) | XL |
| 9 | Invoice pipeline: scheduler `:10` biller port (OrganizationBillingService UNION query), `BillSubscriptionJob` (unique 12h, retry stanzas, retry-with-invoice), `Invoices::SubscriptionService` → `CreateGeneratingService` (status 3) → `CalculateFeesService` (proration, 8 charge models) → `ComputeTaxesAndTotalsService` (tax chain snapshot rows in `fees_taxes`, coupons) → `FinalizeService` (AASM 0→1, assigns number via Sequenced); invoice REST subset (create/update/show/index/void/finalize/refresh) | `jobs/clock/subscriptions_biller_job.rb`, `services/subscriptions/organization_billing_service.rb`, `services/invoices/*`, `services/fees/*`, `services/credits/*` → `Jobs/Clock/*`, `Services/{Invoices,Fees,Credits}/*` | calculate_fees/compute_taxes specs, 8 charge-model specs, bill_subscription_job_spec, invoices request specs | XL |
| 10 | Webhooks (M1 surface): endpoints CRUD, HMAC signature port, `SendHttpWebhookJob` (30s timeout, 3 attempts, backoff `executions**4+jitter`, 64KB response capture, SSRF guard), emission points for customer/subscription/invoice events only | `webhook_endpoints_controller.rb`, `jobs/send_webhook_job.rb`, `send_http_webhook_job.rb`, `services/webhooks/*` | request + job specs | M |
| 11 | GraphQL M1 surface: SDL verbatim; resolvers for the operations the Vue frontend actually issues (trace `front/src/graphql/**`); BigInt-as-string scalar, ISO8601 scalars (UTC `…Z`), 2 interfaces, 5 unions, custom `@lagoPaginate` (page/limit, max 25, `pageInfo`+`collection`+meta), max_depth 15 / max_complexity 350 / 15,000-char query cap, error extensions `{status,code,details(lowerCamel)}`; introspection-diff test vs schema.json | `schema.graphql` + matching resolvers/mutations | spec/graphql/** ports + introspection diff | L–XL |
| 12 | Vue frontend E2E hardening against Laravel (fix what the UI exposes) | — | Pest tests born from failures | M |

Critical path: 0 → 1 → 5 → 7 → 8 → 9. Tasks 5–7 parallelize after 3; 11 starts
after 2 and proceeds slice-by-slice alongside 4–9.

## Contract conventions (must replicate byte-for-byte)

- REST snake_case + `lago_id`-style keys, written literally in serializers
  (never auto-converted — avoids Str::snake acronym bugs). GraphQL auto-camelized;
  error details lowerCamel. Money: integer cents in REST (JSON numbers), BigInt
  strings in GraphQL.
- Pagination meta **in body**: `{current_page,next_page,prev_page,total_pages,total_count}`
  (null/0 conventions when empty), `page`/`per_page` default 100; count cached
  30 min. GraphQL: `page`/`limit`, Relay connections, max page 25.
- REST lookups: `code` (plans, metrics), `external_id` (customers,
  subscriptions — route regex must allow dots), UUID (invoices).
  `DELETE /subscriptions/:external_id` = terminate, never destroy.
- Entire v1 table mirrored at `/api/v2` + `X-Lago-Endpoint-Status: beta`.
- No rate limiting (don't add). CORS from `LAGO_FRONT_URL`/`LAGO_DOMAIN`,
  expose only `x-lago-token`. Non-GET REST writes append `api_logs` + audit.

## Key risks & mitigations

1. **Lighthouse/L13 compat** → T0.1 spike, L12 fallback (cheap).
2. **Numeric precision** → bcmath casts everywhere; banned-float larastan rule
   over Services/{Invoices,Fees,Credits}; PDO returns numeric as string — never
   float-touch it.
3. **Timezone-naive timestamps** → all customer-timezone math via
   `Carbon::parse($naive,'UTC')->setTimezone($tz)` and back; dates-service test
   matrix + per-charge-model contract scenarios are the defense (top silent-bug
   risk: proration/period boundaries).
4. **Advisory locks** → same-connection transaction discipline (unit test with
   query-log assertion); 55P03 → retryable.
5. **Unique-job key building** (`BillSubscriptionJob` lock key embeds the
   timezone-normalized date) → port key construction exactly or double-billing.
6. **JSONB quirks** (`'"{}"'` string default, symbol-vs-array) → tolerant
   `PropertiesCast`; serializers emit exact Rails keys.
7. **versions table** → Rails class names in `item_type`; check
   object/object_changes column types before writing.

## Verification

**M0 gates:** `/health` green in compose; pg_dump diff clean vs structure.sql;
inventories generated + committed; introspection-only `/graphql` matches
schema.json; base concerns unit-tested; CI (pint, larastan, pest, schema-diff,
introspection-diff) green.

**M1 demo (human E2E):** compose with Laravel profile, Vue frontend pointed at
`api-laravel`: login (JWT + silent renewal), create metric + plan (standard +
graduated charges) + customer + pay-in-advance subscription → invoice appears
immediately; arrears subscription → run biller → draft→generating→finalized;
webhook receiver sees signed `customer.created` / `subscription.created` /
`invoice.finalized`. **Swap test:** same walkthrough against the Rails API —
proves the drop-in claim.

**M1 CI gates:** full pest suites; `--group contract` goldens green;
`inventory:check --milestone=m1` exit 0; introspection diff clean; schema-freeze
diff clean; pint + larastan + banned-float lint green.

## Later milestones (planned, not designed here)

M2 events + metering (ClickHouse store abstraction, partman re-enable), M3
wallets/credits/coupons depth, M4 payment providers (Stripe first) + email + PDF
depth, M5 analytics + Kafka consumers + full-surface parity audit (every
inventory row closed) → decommission Rails.
