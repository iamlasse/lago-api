# Contract test harness (Rails goldens → Laravel replay)

Compares this Laravel port, request by request, against responses captured
from the **real Rails API** (`/tmp/lago-api-exploration`, a shallow clone of
getlago/lago-api — the exact source the frozen schema came from). Rails owns
the contract; the Normalizer allowlist (`tests/Contract/Normalizer.php`) is
the only permitted slack.

```
tests/Contract/goldens/<scenario>/
  ├── fixture.sql    pg_dump --data-only --inserts of the scratch Rails DB
  │                 (dumped BEFORE the scenario's requests — see "Gotchas")
  ├── manifest.json  {captured_at, requests: [{method, path, headers, body, required_headers}]}
  └── 1.json …       parsed response body per request, 1-based manifest order
```

## One-time setup

```bash
# 1. Image: the upstream Dockerfile with the TEST group included.
#    BUNDLE_WITH=test pulls factory_bot_rails/faker/rspec — the Dockerfile's
#    hardwired BUNDLE_WITHOUT="development test" LOSES to BUNDLE_WITH
#    (bundler: `with` wins), so no Dockerfile changes are needed.
docker build -t lago-rails-capture \
  --build-arg BUNDLE_WITH=test /tmp/lago-api-exploration

# 2. Scratch DBs on the frozen Postgres container (port 5433):
#    lago_rails_golden   — Rails capture target (recreated on every capture)
#    lago_laravel_golden — Laravel replay target
docker exec lago-laravel-pg createdb -U postgres lago_rails_golden
docker exec lago-laravel-pg createdb -U postgres lago_laravel_golden
```

Never touch the `lago`, `lago_test`, `lago_test_a-r` databases.

## Capture (per scenario)

```bash
scripts/contract/capture.sh auth_org
```

The script (all steps overridable via env — see the header):

1. starts `lago-rails-capture` if not running — `--entrypoint sleep infinity`
   is REQUIRED: the image's ENTRYPOINT (`scripts/start.sh`) runs
   `db:migrate` + puma, and its rake-task load dies on the development-only
   `annotate_rb` gem. It also injects a throwaway `LAGO_RSA_PRIVATE_KEY`
   (base64 PEM) for `config/initializers/rsa_keys.rb` — no `config/keys/`
   exists in a fresh clone, and the value is not contract-relevant;
2. recreates `lago_rails_golden` and loads Rails' own
   `db/structure.sql` into it via psql (identical lineage as
   `database/frozen/structure.sql`; the frozen loader is NOT used on the
   Rails side);
3. docker-copies `scenarios/<scenario>.rb` in and runs
   `bin/rails runner` with `RAILS_ENV=test` (test env skips
   `License.verify`, uses `DATABASE_URL`, and has the test gems). The
   scenario seeds with Rails' factories at deterministic ids under
   `travel_to`, dumps the **seed state** to `fixture.sql`, then issues each
   request in-process via `ActionDispatch::Integration::Session` (no rspec,
   no web server) and writes `1.json`, `2.json`, … + `manifest.json`;
4. copies the goldens out to `tests/Contract/goldens/<scenario>/`.

## Replay

```bash
PAO_DISABLE=1 DB_DATABASE=lago_laravel_golden ./vendor/bin/pest tests/Contract
```

- `DB_DATABASE` — a disposable DB; the test self-migrates the frozen schema
  on first run (AuthOrgTest::loadFixture) and truncates + reloads the
  fixture before each test, so repeated runs are idempotent.
- `PAO_DISABLE=1` — skip the laravel/pao autoload shim, same as the main suite.
- Scenarios without a `manifest.json` skip (existing behavior); `auth_org`
  runs and must pass.
- The clock: `ContractCase::rewindTime` sets BOTH `CarbonImmutable::setTestNow`
  AND `Firebase\JWT\JWT::$timestamp` — Rails captured under `travel_to`,
  which stubbed `Time.now` inside the jwt gem too; without the JWT override
  every captured token is "expired" against the wall clock.
- Jobs: `Queue::fake()` in the test setUp mirrors Rails' `:test` ActiveJob
  adapter (record, never run).
- The fixture is loaded through `DB::unprepared` (PDO multi-statement exec),
  hence `--inserts` in the dump. Its `set_config('search_path', '', false)`
  poisons the shared PDO session — loadFixture resets `SET search_path TO
  public` afterwards, do not remove that line.

## Captured scenarios

| scenario | requests | replay | notes |
| --- | --- | --- | --- |
| `auth_org` | 4 | green | + 2 cross-language JWT side-assertions |
| `customers_crud` | 8 | green | create/upsert, show, index, 404, destroy |
| `plans_metrics_crud` | 13 | green | finding 3 closed (metric filters) |
| `subscription_create` | 8 | green | finding 4 closed (`connections`) |
| `invoice_standard` | 5 + extra `10.json` | green | one-off invoice surface + show |
| `invoice_graduated` | 2 + extra `10.json` | green | finding 12 CLOSED (live aggregation) |
| `invoice_package` | 2 + extra `10.json` | green | finding 12 CLOSED (live aggregation) |
| `invoice_percentage` | 2 + extra `10.json` | green | finding 12 CLOSED (count + running_total live) |
| `invoice_volume` | 2 + extra `10.json` | green | finding 12 CLOSED (live aggregation) |
| `invoice_graduated_percentage` | 2 + extra `10.json` | green | premium flip, gotcha below |
| `wallets_lifecycle` | 13 | red (findings 14-16) | wallet CRUD, paid/granted txs, pool void, tx filters, terminate |
| `coupons_lifecycle` | 14 | green | update-after-apply immutability, applied destroy, coupon destroy |
| `credit_notes_lifecycle` | 8 | red (findings 17, 18, 24) | premium flip on BOTH sides (see gotcha), invoice seeded via in-process billing |
| `events_ingestion` | 9 | red (findings 19, 20) | duplicate dedup, expression metric, mixed batch, show/index |
| `entitlements_crud` | 13 | red (findings 21, 22) | typed privileges, plan PATCH/POST, subscription override |
| `catalog_crud` | 16 | red (findings 23, 24) | /api/v2 surface, product_catalog flag, pending contract, rate phases |
| `taxes_crud` | 10 | green | finding 25 CLOSED (billing-entity tax attach/detach) |
| `webhook_endpoints_crud` | 9 | green | event_types `["*"]`→null normalization, scalar must_be_array, minted-id tokens |
| `metrics_extras` | 8 | green | evaluate_expression F-notation strings + error envelopes, PATCH vs PUT filters batch |
| `invoice_actions` | 14 | green | findings 26-28 CLOSED (Float#to_d / BigDecimal division / sequenced numbering) |

The five per-charge-model scenarios (`invoice_graduated`, `invoice_package`,
`invoice_percentage`, `invoice_volume`, `invoice_graduated_percentage`) share
one shape, documented in `scenarios/invoice_graduated.rb` and replayed by the
shared base class `tests/Contract/InvoiceChargeModelCase.php`: seed org +
api key + customer + sum metric + ONE charge of the model under test +
metered events + one `cached_aggregations` row, then manifest request #1 =
`POST /subscriptions` (arrears, calendar, `subscription_at` = period start),
then IN-PROCESS billing via `BillSubscriptionJob.perform_now` under
`travel_to(BILLING_AT)` (the production billing entry point — billing is a
clock path, not an HTTP path), then manifest request #2 = invoice index and
EXTRA golden `10.json` = invoice show of the minted invoice (replay
substitutes the id its own billing minted, like `invoice_standard`).

**How the metered input is seeded (both sides must aggregate the same):**
three `:event` rows (deterministic transaction ids, `timestamp` inside the
billed May period) ride `fixture.sql`; PLUS one `:cached_aggregation` row
with the same sum for the same charge/subscription. The events are the
aggregation input on BOTH sides (the fee engine aggregates them live since
finding 12 closed). The cached row stays seeded as a negative control: the
Laravel Aggregator — like Rails — must IGNORE cached rows on the arrears
periodic path (they are only read for pay-in-advance current usage and the
recurring weighted-sum carry-over), so the replay stays green only while
the live events path is honored. Everything downstream (tiering math,
amount_details) still has to match.

Replay DBs: each scenario test self-migrates, so any disposable DB works.
Dedicated `lago_golden_<scenario>` databases exist on `lago-laravel-pg`;
running the whole suite sequentially on the shared `lago_laravel_golden`
also works (every test truncates + reloads its fixture in setUp).

## Cross-language JWT (auth_org)

Both runtimes sign HS256 with the SAME `SECRET_KEY_BASE` (read from this
repo's `.env`; Rails gets it via the capture script's env):

- Rails-minted token (manifest request #4) must decode in Laravel — asserted
  in `AuthOrgTest::test_rails_minted_jwt_from_the_capture_authenticates_in_laravel`.
- Laravel-minted token must authenticate `/graphql` currentUser — asserted
  in `AuthOrgTest::test_laravel_minted_jwt_carries_the_same_claims_rails_would_sign`.

Token BYTES are never compared: the jwt gem emits header `{"alg":"HS256"}`,
firebase/php-jwt `{"typ":"JWT","alg":"HS256"}`. The Normalizer canonicalizes
`token` fields to their decoded CLAIMS (sub / exp / login_method), which must
match exactly — exp matches because both sides mint under the frozen clock.

## Adding a scenario (mechanical steps)

1. Copy `scenarios/auth_org.rb` → `scenarios/<name>.rb`. Rules:
   - deterministic ids for anything a response echoes (Faker values are fine
     ONLY if they come back from the fixture on replay — the org email does);
   - freeze the clock with `travel_to` and record `CAPTURED_AT` in the
     manifest;
   - dump the SEED STATE to `fixture.sql` BEFORE issuing requests;
   - write `<n>.json` per request (manifest order, 1-based) + `manifest.json`
     into `ENV["LAGO_GOLDENS_DIR"]`;
   - set `required_headers` sparingly: only headers that are contract
     (`X-Lago-Endpoint-Status: beta` on /api/v2, `x-lago-token` when
     renewed). Content-Type differs cosmetically (Rails appends
     `; charset=utf-8`) — do not require it.
2. `scripts/contract/capture.sh <name>`; commit
   `tests/Contract/goldens/<name>/`.
3. Add `tests/Contract/<Name>Test.php` extending `ContractCase`, set
   `$scenario`, call `$this->runScenario()`, add any side-assertions.

## Gotchas (all hit for real while wiring auth_org)

- `docker run` on the image without `--entrypoint sleep` fails: start.sh's
  rake load hits `cannot load such file -- annotate_rb` (development group).
- `bin/rails runner` needs `RAILS_ENV=test`; development boot dies on the
  graphiql initializer (graphiql-rails is not installed without the dev
  group).
- The membership factory's singular `role:` transient is a trap — pass
  `roles: [:admin]` (symbols map to membership_role traits); a string raises
  AssociationTypeMismatch.
- Rails 8 integration helpers take kwargs only: `session.get(path,
  params:, headers:)` — a String `params` is the raw request body.
- pg_dump 18 client vs PG15 server emits `\restrict`/`\unrestrict` psql
  meta-commands and `SET transaction_timeout` — both are stripped in the
  scenario (PDO chokes on the former, PG15 on the latter). Also exclude the
  `partman` schema (extension tables absent from the Laravel schema).
- Data-only dumps are ordered topologically, but tables in circular FK
  cycles (charges, fees, …) may restore out of order. Harmless while those
  tables are empty — revisit if a scenario seeds them.
- Circular side effects: `PUT /organizations` with `webhook_url` DOES create
  the webhook endpoint in the DB, but the captured response shows the stale
  in-memory association (`webhook_url: ""`). This is the contract; see
  findings below.
- The Rails image's pg_dump runs inside the capture container against
  `host.docker.internal:5433` (the frozen `lago-laravel-pg`). Keeping the
  dump inside the scenario is what lets it snapshot the PRE-request state.
- Seeds that later index responses will list should be created at a DIFFERENT
  frozen instant than the requests (`travel_to(CAPTURED_AT - 3600) { seed }`
  then `travel_to(CAPTURED_AT) { dump + requests }`): several list endpoints
  sort by `created_at desc` without an id tie-break (plans/charges index in
  particular — BaseQuery's `apply_consistent_ordering` DOES add
  `.order(id: :asc)`, but not every controller goes through it).
- Rows created by the requests THEMSELVES can also tie: two rows minted in
  the same frozen instant share created_at, and ordering falls to a
  minted-uuid tie-break that differs per runtime. When a list may hold two
  request-created rows, make the later one deterministic — e.g. an explicit
  `subscription_at` on the second subscription create (see
  subscription_create.rb). This bit for real: customers_crud flaked on the
  shared-DB full-suite run before the seed-hour fix.
- Request bodies that reference seeded rows by UUID (e.g. plan create's
  `billable_metric_id`) must reference DETERMINISTIC ids set on the seed:
  the replay sends the manifest body verbatim, so the referenced UUID has to
  exist in the fixture on both sides.
- Anything a request MINTS (created row ids, minted tokens) cannot be
  compared by bytes: see Normalizer TOKEN_FIELDS / MINTED_ID_FIELDS. An id
  keyed to a minted row cannot even appear in the manifest path — capture it
  as an EXTRA golden outside the manifest numbering and have the test
  substitute the id its own replay minted (see invoice_standard's `10.json`
  + InvoiceStandardTest::test_shows_the_invoice_the_replay_created).
- Rails' invoice preview is premium-gated in the OSS image —
  `POST /api/v1/invoices/preview` answers 403
  `{"status":403,"error":"Forbidden","code":"feature_unavailable"}`. That
  envelope IS the contract for the OSS capture stack; keep it.
- Rails' `ActiveSupport::Testing::TimeHelpers` refuse NESTED `travel_to`
  blocks (RuntimeError) — the in-process billing needs its own top-level
  `travel_to(BILLING_AT)` trip, and any locals (`auth`) must be re-created
  inside each block (Ruby block scoping).
- `graduated_percentage` is `License.premium?`-gated in Rails
  (`charge.rb:181`); the OSS capture image has no license, so
  `invoice_graduated_percentage.rb` flips the singleton the same way the
  Rails suite's own `:premium` specs do
  (`License.instance_variable_set(:@premium, true)` — see
  spec/support/license_helper.rb) BEFORE seeding the charge. The fee
  pipeline downstream is unmodified production code.
- The in-process `BillSubscriptionJob.perform_now` replay must freeze the
  SAME instant on both sides (BILLING_AT): the invoice's created_at,
  issuing_date clock and fee timestamps come from it. The Laravel test
  mirrors it with `CarbonImmutable::setTestNow(BILLING_AT)` around
  `(new BillSubscriptionJob(...))->handle()` and restores the captured
  instant afterwards (see `InvoiceChargeModelCase::billInProcess`).
- **Per-request frozen instants (`at`)**: when several request-CREATED rows
  feed the SAME index and would TIE on created_at (falling to the
  minted-id tie-break), the scenario freezes each request at its OWN
  instant — `capture!` takes `at:` (default CAPTURED_AT), records it in
  the manifest as `at`, and wraps the request in a TOP-LEVEL `travel_to`
  (the seed/dump block must be CLOSED before the first request — nested
  travel_to raises, see the TimeHelpers gotcha above). `ContractCase::runScenario`
  honors `at`: it advances the replay clock per request
  (`freezeRequestInstant`) and restores the scenario-wide instant after.
  wallets_lifecycle's transactions index (three POSTs one frozen second
  apart) is the reference.
- **Minted-id TOKENS in the manifest**: when request N addresses a row
  MINTED by an earlier captured request (show a created wallet transaction,
  terminate a created applied coupon), the scenario sends the REAL id to
  Rails but writes a TOKEN into the manifest (`capture!` `tokens:` option
  gsubs path and body). The replay test overrides
  `substituteRequestValues` and calls `ContractCase::replaceTokens` with
  the id ITS own request minted (see WalletsLifecycleTest: the token is
  read from the earlier response's `wallet_transactions.0.lago_id`).
- **`lago_coupon_id` is a minted-id field** (Normalizer MINTED_ID_FIELDS):
  applied coupons echo the id of a coupon created by an earlier captured
  request; each runtime its own. Unit test
  `NormalizerTest::test_minted_lago_coupon_ids_compare_as_uuids`.
- **Coupons require `expiration`** (enum `no_expiration` | `time_limit`)
  — a create without it 422s `value_is_invalid`.
- **Events dedup needs `external_subscription_id`**: the unique index is
  `(organization_id, external_subscription_id, transaction_id)`; with a
  NULL subscription Postgres treats duplicates as distinct and the
  duplicate transaction_id case (the point of the scenario) never fires —
  events_ingestion seeds a subscription and addresses every event to it.
- **Batch events' created_at is the DATABASE clock**: Rails' batch ingest
  writes through a bulk INSERT (insert_all — travel_to cannot stub it), so
  the golden would bake in the capture DAY's wall clock. The scenario
  re-stamps the batch rows AND golden 7.json to the frozen instant
  (runtime state, not contract — see events_ingestion.rb).
- **Credit notes are premium-gated in BOTH runtimes**
  (`CreditNotes::CreateService` / `App\Support\License::premium()`). The
  scenario flips the Rails singleton (`License.instance_variable_set`); the
  replay flips the same switch via `config(['lago.license' => ...])` in the
  test setUp (test-harness config, NOT an app change).
- **The v2 catalog is a rollout FLAG, not a license gate**: every native
  /api/v2 controller requires the organization's
  `feature_flags: ["product_catalog"]` — the catalog_crud org is seeded
  with it. Every /api/v2 response carries `X-Lago-Endpoint-Status: beta`
  (asserted via `required_headers`).
- **Contracts are editable only while PENDING** (`Contract#editable?`) —
  an `active` contract (default when started_at is not in the future)
  answers `contract_locked` on applied-rate-card writes, so catalog_crud
  creates its contract with a frozen month-in-the-future `started_at`.
- **Feature updates REPLACE the whole privilege set**
  (Features::UpdateService): re-declare the existing privileges when
  adding one. The entitlements PATCH/POST body shape is
  `{entitlements: {feature_code: {privilege_code: value}}}` — NO
  "privileges" wrapper (a wrapper is read as a privilege literally → 404
  privilege_not_found).
- **Rate phases: only the LAST phase may be indefinite** — a phase without
  `billing_interval_cycle_count` 422s `indefinite_phase_must_be_last` when
  it is not last.

### Gotchas from the 2025-06-12 capture wave (taxes / webhook_endpoints / metrics_extras / invoice_actions)

- **Duplicate tax code is `value_already_exist`** (singular) — that is what
  Rails' uniqueness message carries verbatim; the obvious
  `value_already_exists` guess will diff.
- **`evaluate_expression` values are STRINGS** — the lago-expression gem
  returns BigDecimal and the JSON encoder renders `21.0` as `"21.0"`,
  `event.timestamp` as `"1749740400.0"` (F-notation with a trailing `.0`).
  The replay must run the expression result through the same BigDecimal
  rendering as the serializers, not emit a JSON number.
- **`event.timestamp` falls back to the FROZEN clock** — an event without
  `timestamp` gets `Time.current` inside `travel_to`, so golden 2.json of
  metrics_extras is deterministic (the replay must freeze the same instant).
- **Webhook `event_types: ["*"]` normalizes to `null`** (filtering DISABLED,
  golden shows `event_types: null`); invalid types 422 with the offenders
  embedded IN the message (`contains invalid types: ["not_a_real_event"]`);
  a scalar `event_types` survives `params.permit` ON PURPOSE so the model
  can raise `must_be_array` — the Laravel port must not drop the scalar at
  validation.
- **The static-parse inventory's `new`/`edit` rows are false positives** for
  taxes/billable_metrics — Rails' `resources` registers the routes but the
  API controllers have no such actions (they would 500); the scenarios skip
  them.
- **PATCH payment_status leaves fees `pending`** — the fee sync is
  `Invoices::UpdateFeesPaymentStatusJob` (`perform_after_commit`), which
  Rails' `:test` adapter records but never runs. The replay's `Queue::fake()`
  mirrors this — do not "fix" the divergence by running the job.
- **PUT /invoices/:id/refresh re-derives the billing boundaries and PRORATES**
  — RefreshDraftService destroys the seeded invoice_subscription and rebuilds
  it via CreateInvoiceSubscriptionService; in the captured fixture that
  yields a degenerate period (subscription_from == subscription_to ==
  2025-06-01T00:00:00Z) and a subscription fee of 158c (4900 over a 31-day
  base). Deterministic, but the replay must reproduce the boundary math, not
  expect the seed's full-month values. Finalize re-runs the same refresh and
  only moves issuing_date to the frozen date + flips status.
- **PUT finalize/refresh on a non-subscription invoice is `forbidden_failure!`**
  — both services bail (`invoice.subscription?`) before anything else; the
  scenario's draft invoice is seeded as `invoice_type: subscription` via the
  `:subscription` factory trait for that reason.
- **Minted-id tokens in these manifests**: `ONE_OFF_INVOICE_ID`
  (invoice_actions #2/#3), `WEBHOOK_ENDPOINT_ONE_ID` (#4/#5) and
  `WEBHOOK_ENDPOINT_TWO_ID` (#8) — the replay tests substitute the ids their
  own requests minted (same mechanics as wallets_lifecycle).

## Current findings (Laravel deviations, intentionally not fixed)

Replay of auth_org fails on exactly two paths — genuine port bugs, left
red on purpose (rules: capture, don't fix):

1. **`GET /api/v1/organizations` emits `"taxes": []`; Rails omits the key.**
   Rails' show passes `include: %i[taxes]` (singular) but its
   `ModelSerializer#include?` reads only `options[:includes]` (plural), so
   GET responses have no `taxes` key; only PUT (which passes `includes:`)
   appends it. Laravel's `app/Http/Controllers/Api/V1/OrganizationsController.php`
   passes `['includes' => ['taxes']]` on BOTH paths (line 31 = show).
   Diff: `$.organization.taxes` — golden: absent, Laravel: `[]`.
   (FIXED since this was first captured — auth_org replays green now.)

2. **`PUT /api/v1/organizations` echoes a fresh webhook endpoint; Rails
   echoes the stale association.** With no pre-existing endpoint, Rails'
   UpdateService does `webhook_endpoints.first_or_initialize` + `update!`
   (endpoint IS persisted — verify in `lago_rails_golden`), but the response
   serializes the already-loaded association: golden shows
   `webhook_url: ""`, `webhook_urls: []`. Laravel shows the new URL.
   Diff: `$.organization.webhook_url` — golden `""`, Laravel the URL;
   `$.organization.webhook_urls` — golden `[]`, Laravel 1 item.
   (FIXED since this was first captured — auth_org replays green now.)

3. **`plans_metrics_crud` — billable metric `filters` always `[]`.**
   Rails serializes the metric's billable_metric_filters
   (`[{key: "region", values: ["eu", "us"]}]`); Laravel's
   `app/Serializers/V1/BillableMetricSerializer.php::filters()` hardcodes
   `return ['filters' => []];` (marked TODO(port): BillableMetricFilter
   serializer). Affects show / update / destroy / index of any metric that
   has filter keys.
   Diff: `$.billable_metric.filters` (4 requests) — golden: 1 item
   `{key:"region", values:["eu","us"]}`, Laravel: `[]`.

4. **`subscription_create` — `connections` emitted as `{}`.** Rails
   serializes `model.connection_routing` (default rows: payment / tax /
   accounting / crm, each `{behavior: "inherit", code: null}`); Laravel's
   `app/Serializers/V1/SubscriptionSerializer.php` emits an empty map
   (marked TODO(port): ConnectionResolvable). Affects every response that
   embeds a subscription (create, show, index, update, terminate).
   Diff: `$.subscription.connections.{payment,tax,accounting,crm}` —
   golden `{behavior:"inherit", code:null}`, Laravel: `null` (7 requests;
   only show-after-terminate 404 matches).

5. **`invoice_standard` — the whole REST invoice surface is unregistered.**
   - `POST /api/v1/invoices` (one-off invoice, Invoices::CreateOneOffService):
     Laravel answers 405 MethodNotAllowed (only GET index-style routes are
     absent too — the fallback returns `resource_not_found`). Rails: 200 with
     the full invoice — `fees_amount_cents: 2400, taxes_amount_cents: 480,
     total_amount_cents: 2880` on a 1200c x 2 add-on fee with 20% VAT, plus
     `fees[]`, `applied_taxes[]`, `number: "WAL-5C0D-002-001"`.
     Diff: `$.invoice` — golden: full object, Laravel: `null` (+405 envelope).
   - `GET /api/v1/invoices?external_customer_id=…`: Laravel 404
     `resource_not_found` (route not registered). Diff: `$.invoices`/`$.meta`.
   - `POST /api/v1/invoices/preview`: Rails (OSS image) 403
     `feature_unavailable`; Laravel 405 (route not registered).
   Extra golden `10.json` (invoice show) is committed but skipped in replay
   until POST /invoices lands (InvoiceStandardTest skips with that reason).

6. **Laravel error responses leak debug payloads.** On unmatched routes the
   replay returns `message`, `exception`, `file`, `line` and a full `trace[]`
   alongside the envelope (APP_DEBUG on in the test env); Rails never emits
   these keys. Every 404/405 diff above includes them. Decide whether the
   test env should render production-shaped errors or the Normalizer should
   drop debug keys — but today the Laravel test stack answers differently
   from production Laravel too.

6. **Laravel error responses leak debug payloads.** On unmatched routes the
   replay returns `message`, `exception`, `file`, `line` and a full `trace[]`
   alongside the envelope (APP_DEBUG on in the test env); Rails never emits
   these keys. Every 404/405 diff above includes them. Decide whether the
   test env should render production-shaped errors or the Normalizer should
   drop debug keys — but today the Laravel test stack answers differently
   from production Laravel too.

The five per-charge-model scenarios replay green end to end: finding 12 is
CLOSED — the fee engine's Aggregator aggregates the seeded `events` LIVE
(`events_count` and the percentage per-event running_total come from the
events store, like Rails), and the seeded `cached_aggregations` row is
correctly ignored on the arrears periodic path. Findings 7–11 and 13 are
CLOSED too; the history is kept below with their root causes, since the
tests that pinned the bugs travel with the fixes.

7. ~~**Invoice show: `billing_periods` is `[]` (all 5 scenarios).**~~ CLOSED:
   `app/Serializers/V1/Invoices/BillingPeriodSerializer.php` ports
   `V1::Invoices::BillingPeriodSerializer` (one entry per invoice_subscription,
   ordered by COALESCE(subscription name, plan invoice_display_name, plan name)).

8. ~~**Package charge rounds packages DOWN instead of UP.**~~ CLOSED:
   `PackageService` now uses `MoneyMath::ceil` like Rails'
   `paid_units.fdiv(per_package_size).ceil` — 111 paid units in packages of
   10 bill 12 packages (120000c). Unit test:
   `rounds a partial package UP, not half-up (Rails ceil)`.

9. ~~**Percentage charge: per-event branches (CLOSED at the model level; the
   CONTRACT replay stays red on the seam).**~~ CLOSED end to end (finding 12
   fixed): two port bugs were fixed — `per_unit_total_amount` emitted Rails'
   DEAD-CODE expression (`compute_percentage_amount.fdiv(paid_units)` —
   result discarded in Ruby, so the golden value is
   `compute_percentage_amount` verbatim), and `fixed_fee_unit_amount` keyed
   on `paid_units > 0` instead of Rails' `paid_events.positive?`. The full
   golden math (fee 1315c, free_events 1, paid_events 3, fixed_fee_total
   "6.0", per_unit_total "7.15") is pinned by the unit test
   `replays the invoice_percentage golden math` with Rails-shaped inputs
   (count 4, running_total limited to the first free_units_per_events
   values per SumService#running_total_per_events) — and since finding 12
   closed, the live aggregation feeds the same inputs from the seeded
   events and the contract replay matches the goldens.

10. ~~**Fee-level `units` off by 10x for volume and percentage.**~~ CLOSED —
    and the cause was NOT a scale bug: the fee's stored units were correct
    (250 / 800); the serializer's `decimalToF` helper rtrimmed trailing
    zeros from the WHOLE string, so dotless "250" became "25.0" and "800"
    became "8.0". The shared `MoneyMath::toF` only trims FRACTIONAL zeros.

11. ~~**Fee `item` (invoice show) serialization incomplete (all 5).**~~ CLOSED:
    subscription fees carry plan name/description + subscription name (via
    Fee#invoice_name port) + subscription id as `lago_item_id`; charge fees
    carry the charge invoice_display_name and the BILLABLE METRIC id. The
    subscription item's minted id is canonicalized in the Normalizer
    (`item.type === "subscription"` only — charge items still compare
    strictly; unit test
    `test_minted_subscription_item_ids_compare_as_uuids`).

12. ~~**Aggregation seam starves the fee metadata (OPEN — M2).**~~ CLOSED:
    `app/Services/Events/Stores/PostgresStore.php` ports Rails'
    Events::Stores::PostgresStore aggregation API (sum / count / max / last /
    unique_count / weighted_sum + grouped & prorated variants, boundary and
    property filters) over the frozen `events` table, and
    `app/Services/BillableMetrics/AggregationFactory.php` +
    `Aggregations/*Service` port the Rails aggregation services. The fee
    engine's Aggregator now resolves the aggregation service from the
    charge's billable metric and aggregates the events LIVE: fees carry the
    real `events_count` (3 for graduated/package/volume, 4 for percentage)
    and the percentage model gets SumService's per-event `running_total`.
    The cached-aggregations decision matches Rails: periodic in-arrears
    billing NEVER reads `cached_aggregations` (the seeded row is ignored);
    cached rows are only consulted on the pay-in-advance current-usage
    paths and for the recurring weighted-sum carry-over. Unit tests:
    `tests/Unit/Services/Events/PostgresStoreTest.php` (store SQL vs the
    Rails store spec expectations) and
    `tests/Unit/Services/Fees/ChargeService/AggregatorTest.php` (the
    cache-vs-live decision).

13. ~~**BigDecimal string formatting (all 5).**~~ CLOSED:
    `MoneyMath::toF` is the canonical Rails `to_s("F")` port (fixed notation,
    trailing FRACTIONAL zeros trimmed, at least one decimal — "250" stays
    "250.0"), used by the fee/coupon/applied-coupon serializers. Fee
    `amount_details` values are formatted at jsonb-write time
    (`ChargeService::serializeAmountDetails` — Rails' ActiveSupport encodes
    BigDecimal values the same way on save), with integers (event counts,
    range bounds, `per_package_size`) passing through untyped. The
    `precise_unit_amount` final digit comes from BigDecimal division
    semantics: `MoneyMath::fdiv` now ROUNDS at scale 15 (half away from
    zero, like `BigDecimal#div`) instead of truncating — golden
    "7.761904761904762", not "...761".

Not red but load-bearing: `lago_subscription_id` (fees, billing_periods)
now canonicalizes as a minted id in the Normalizer — the subscription is
minted by manifest request #1, so each runtime echoes its own id (unit test:
`NormalizerTest::test_minted_lago_subscription_ids_compare_as_uuids`).

### Findings from the six new scenarios (captured 2025-06-06 … 2025-06-11, CLOSED)

14. ~~**`wallets_lifecycle` — decimal fields emit raw DB scales, not Rails'
    `to_s("F")`.**~~ CLOSED: `MoneyMath::toF` applied to the wallet credit
    decimals in `WalletSerializer` (rate_amount, credits_balance,
    credits_ongoing_balance, credits_ongoing_usage_balance,
    consumed_credits) and `WalletTransactionSerializer` (amount,
    credit_amount, remaining_credit_amount) — Rails' ActiveSupport JSON
    encoder renders BigDecimal attributes with to_s("F") even where the
    serializer body just emits `model.x`.

15. ~~**`wallets_lifecycle` — `limitations` / `applies_to` key swap.**~~
    CLOSED, and the CONFLICT resolved against the Rails source: the
    serializer's private `limitations` method returns
    `{ applies_to: {...} }` and `payload.merge!(limitations)` merges it at
    the TOP level, so the emitted payload has `applies_to` and NO
    `limitations` key (Rails wallet_serializer.rb:63, 66-70) — the golden
    and the source agree; the earlier "fix" nested the hash under
    `limitations` on the wrong reading of `merge!`. The include is still
    gated by `include('limitations')` (the controller's include list key).

16. ~~**`wallets_lifecycle` — `metadata` double nesting.**~~ CLOSED: Rails
    `payload.merge!(metadata)` where the private `metadata` method returns
    `{ metadata: V1::MetadataSerializer...serialize }` and that serializer
    returns `model&.value` (the flat hash) — so the payload key is
    `metadata` with the FLAT value hash. The port had wrapped the value
    hash a second time.

17. ~~**`credit_notes_lifecycle` — `precise_*` decimal fields.**~~ CLOSED:
    `MoneyMath::toF` on `precise_total_amount_cents` /
    `precise_taxes_amount_cents` (CreditNoteSerializer),
    `items[].precise_amount_cents` (CreditNoteItemSerializer) and the
    estimate's `precise_taxes_amount_cents` /
    `precise_coupons_adjustment_amount_cents` (EstimateSerializer) — Rails
    leaves these as BigDecimal and the JSON encoder formats them.

18. ~~**`credit_notes_lifecycle` — `customer.integration_customers` is
    null.**~~ CLOSED: `CreditNoteSerializer::customer()` passed
    `includedRelations('customer')` as the serializer OPTIONS array
    positionally — the `includes` key was never set, so the nested
    CustomerSerializer saw no includes and dropped the key (the differ
    reads a missing key as null). Now wrapped as
    `['includes' => $this->includedRelations('customer')]`, matching
    InvoiceSerializer; the empty integration_customers collection renders
    as `[]`.

19. ~~**`events_ingestion` — validation messages not translated to error
    codes.**~~ CLOSED: Lago's en.yml overrides the ActiveRecord "blank"
    message with the error code "value_is_mandatory"
    (config/locales/en.yml:7), which is what `record.errors.messages`
    carries when the RecordInvalid details render. Both the single
    (Events/CreateService::assertValid) and batch
    (Events/CreateBatchService::validationMessages) presence mappings emit
    `value_is_mandatory` for transaction_id and code.

20. ~~**`events_ingestion` — batch response omits `updated_at`.**~~
    CLOSED, with a documented golden-vs-source conflict: the checked-out
    Rails snapshot's V1::EventSerializer emits no `updated_at` at all (the
    serializer spec confirms), yet the captured BATCH golden
    (events_ingestion/7.json) carries it while the single
    create/show/index goldens (1/4/8/9.json) do not — so the batch
    endpoint genuinely rendered it in the capturing build. The goldens are
    truth: `EventSerializer` accepts a `with_updated_at` option and only
    the batch action (EventsController::batch) passes it; every other path
    keeps the snapshot shape with the key absent (not null).

21. ~~**`entitlements_crud` — PUT /features/:code serializes a STALE
    privileges relation.**~~ CLOSED: Rails reaches the serializer through
    the association's loaded target — `feature.privileges.new` appends and
    `privilege.discard!` marks in-memory records — while Eloquent's
    make() + child save() and the bulk delete leave the loaded collection
    stale. Features/UpdateService now reloads the privileges relation
    after the transaction commits (same shape as the auth_org webhook_url
    fix).

22. ~~**`entitlements_crud` — privilege list ORDER differs.**~~ CLOSED:
    neither side has a deterministic ORDER BY — Rails' order falls to DB
    heap order (effectively creation order on the replay DB). Pinned
    deterministically to creation order: `FeatureSerializer` sorts
    privileges by created_at then code, `PlanEntitlementSerializer` sorts
    entitlement values by created_at then privilege code, and
    SubscriptionEntitlementQuery's privilege SQL adds `p.code` after
    `ordering_date` (values minted in one request share created_at under
    the frozen replay clock). Matches every captured golden.

23. ~~**`catalog_crud` — POST /api/v2/rate_cards rejects `standard` on a
    fixed product.**~~ CLOSED: the divergence was neither the matrix nor
    the params — `RateCardRate::validateRateModelCompatibility` read the
    model with `getRawOriginal('rate_model')`, and on the UNSAVED rate the
    originals array is empty, so 'standard' reached the matrix as '' and
    fell out of FIXED_ITEM_RATE_MODELS. Now reads the raw attribute (same
    read as validateRateModel). CASCADES CLEARED, and they exposed three
    more diffs behind the 404/422s, all fixed: (a) Rails'
    `before_validation :normalize_effective_from` (beginning_of_day on
    arrears cards) was missing — ported to RateCardRate::validateAttributes;
    (b) the applied-rate-cards index query JOINed contracts with `select *`,
    letting the contracts columns clobber the card's id/created_at/
    updated_at — now `select('contract_rate_cards.*')`; (c) the v2
    serializer shapes — `units` renders through MoneyMath::toF and
    FormatsDatetime::serializeDate now emits Rails' true Date#iso8601
    ("Y-m-d", not midnight-datetime) for DATE columns.

24. ~~**Pagination `meta` off-by-one on some indexes.**~~ CLOSED as a
    cascade of finding 23: with the rate card 422'd, the v2 rate_cards and
    applied_rate_cards indexes replayed against an EMPTY collection and
    reported total_count 0 (current_page 0 with it) where the goldens held
    the 1-row Rails state; with 23 fixed the meta matches. GET /credit_notes
    (v1) never reproduced after the finding-17/18 fixes — its index meta
    was already correct (the README note predated the serializer repairs).

### Findings from the 2025-06-12 wave replays (taxes / webhook_endpoints / metrics_extras / invoice_actions)

25. ~~**`taxes_crud` — `applied_to_organization` never attached the tax to
    the billing entity.**~~ CLOSED: the TODO(port) at the hook point is now
    filled — `app/Services/BillingEntities/Taxes/{Apply,Remove}TaxesService`
    port Rails' same-named services (the `billing_entities_taxes` join is
    written directly — no BillingEntityAppliedTax model, the same
    convention as `Tax::billingEntities`), plus their
    `RefreshDraftInvoicesJob` (dispatched, never run, under the replay's
    `Queue::fake()`). Wired into Taxes::{Create,Update}Service exactly at
    Rails' `apply_taxes_on_billing_entity` / `manage_taxes_on_billing_entity`
    call points.

26. ~~**`invoice_actions` — fee precise_* decimals truncated (and then
    mis-rounded).**~~ CLOSED, two port bugs sharing one root:
    - Rails computes the subscription proration as a FLOAT
      (`single_day_price` is `amount_cents.fdiv(duration)`) and stores
      `Float#to_d` — the capture image's Ruby 4 truncates the double's
      exact binary expansion at 16 significant digits
      ("158.06451612903226" → "158.0645161290322"). The port stringified
      with PHP's `(string)` cast — 14 significant digits — losing two
      digits before anything else ran. Fix:
      `MoneyMath::floatToDecimal()` (the Float#to_d port, used at the four
      `Fees::SubscriptionService` proration sites).
    - The serializers then divided by the subunit at bcmath scale 10. The
      Rails serializer divides by `subunit_to_unit.to_d` — a BigDecimal
      divisor, so `#fdiv` is BigDecimal DIVISION, which truncates the
      exact quotient at 16 significant digits ("1.8967741935483864" →
      "1.896774193548386"). Fix: `MoneyMath::truncateSignificant()` applied
      to `FeeSerializer`'s precise_amount / precise_total_amount /
      taxes_precise_amount.

27. ~~**`invoice_actions` — the draft invoice numbered itself on refresh.**~~
    CLOSED: two halves. Rails' Sequenced concern gates
    `ensure_sequential_id` on `should_assign_sequential_id?`, which Invoice
    overrides with `status_changed_to_finalized?` — whose
    `status_changed?(from:, to:)` kwargs are SWALLOWED by ActiveModel's
    generated dirty predicate, so the real gate is "the status attribute
    changed" (draft keeps NULL until the → finalized save). Ported as
    `Sequenced::ensureSequentialId()` + `shouldAssignSequentialId()`
    (Invoice: dirty-on-status, default-aware for new records). Second half:
    Rails registers Sequenced's callback at `include Sequenced` — BEFORE
    the model's own before_save hooks — so `ensure_number` formats the id
    assigned in the same save ("…-001-002"). Laravel's trait-vs-#[Boot]
    order is not guaranteed, so `Invoice`'s saving hook calls
    `ensureSequentialId()` inline first.

28. ~~**`invoice_actions` — refresh serialized stale fee timestamps.**~~
    CLOSED: Rails runs `CalculateFeesService` on `invoice.reload` — the
    SAME object — so the `invoice.fees.update_all(created_at:
    invoice.created_at)` stamp (the degenerate-period fee carries the
    invoice's created_at) is visible to the serializer. The port refreshed
    a COPY for the fee engine and serialized the original object's cached
    fees relation. `RefreshDraftService` now reloads the invoice after the
    stamp.

    Also fixed en route (goldens are truth):
    - `resend_email` is premium-gated — the replay flips
      `config(['lago.license' => null])` (the OSS capture stack has no
      license; the 403 premium_license_required envelope IS the contract).
      `Emails::ResendService` also ran its precondition chain in the wrong
      order for invoices — Rails checks found → valid_status? (an invoice
      must be FINALIZED; a receipt always is) → premium → validation.
    - `payment_url` / `resend_email` routes + controller actions are
      registered (`GeneratePaymentUrlService` port; its happy path lives
      with the PSP slice — `PaymentIntents::FetchService`).
    - `RefreshDraftService` called `$invoiceSubscription->invoicingReason()`
      — a method that does not exist (500 on every refresh/finalize); the
      model's Rails-`invoicing_reason` accessor is `invoicingReasonName()`.



### Normalizer slack (with unit tests in NormalizerTest)

- `token` fields → decoded JWT claims (both sides mint at the frozen clock).
- `lago_id` fields holding a UUID → `<uuid>`: rows created BY a captured
  request get a fresh uuid per runtime; everything else under `lago_id`
  (null, non-UUID) still compares strictly.
