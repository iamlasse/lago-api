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
| `invoice_graduated` | 2 + extra `10.json` | show red on `events_count` | finding 12 (seam) |
| `invoice_package` | 2 + extra `10.json` | show red on `events_count` | finding 12 (seam) |
| `invoice_percentage` | 2 + extra `10.json` | index red (totals), show blocked | finding 12 (seam) — see finding 9 |
| `invoice_volume` | 2 + extra `10.json` | show red on `events_count` | finding 12 (seam) |
| `invoice_graduated_percentage` | 2 + extra `10.json` | green | premium flip, gotcha below |

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
with the same sum for the same charge/subscription. The cached row exists
because the Laravel aggregation seam
(`app/Services/Fees/ChargeService/Aggregator.php`) reads
`cached_aggregations` and NOT `events` (live event aggregation is M2
there), while Rails ignores cached rows on the arrears periodic path
(they are only read for pay-in-advance event billing) — so the row is the
honest carrier of the seam's input and invisible to the Rails capture.
Everything downstream (tiering math, amount_details) still has to match.
The seam cannot carry the event COUNT or per-event running_total — those
gaps are findings 9/12 below.

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

The five per-charge-model scenarios replay their index requests green and
their shows green except for the aggregation-seam gap (finding 12):
`events_count` on graduated/package/volume/percentage, and the percentage
index totals (the per-event fixed fee — finding 9's data dependency).
Findings 7–11 and 13 are CLOSED; the history is kept below with their root
causes, since the tests that pinned the bugs travel with the fixes.

7. ~~**Invoice show: `billing_periods` is `[]` (all 5 scenarios).**~~ CLOSED:
   `app/Serializers/V1/Invoices/BillingPeriodSerializer.php` ports
   `V1::Invoices::BillingPeriodSerializer` (one entry per invoice_subscription,
   ordered by COALESCE(subscription name, plan invoice_display_name, plan name)).

8. ~~**Package charge rounds packages DOWN instead of UP.**~~ CLOSED:
   `PackageService` now uses `MoneyMath::ceil` like Rails'
   `paid_units.fdiv(per_package_size).ceil` — 111 paid units in packages of
   10 bill 12 packages (120000c). Unit test:
   `rounds a partial package UP, not half-up (Rails ceil)`.

9. **Percentage charge: per-event branches (CLOSED at the model level; the
   CONTRACT replay stays red on the seam).** Two port bugs were fixed:
   `per_unit_total_amount` emitted Rails' DEAD-CODE expression
   (`compute_percentage_amount.fdiv(paid_units)` — result discarded in Ruby,
   so the golden value is `compute_percentage_amount` verbatim), and
   `fixed_fee_unit_amount` keyed on `paid_units > 0` instead of Rails'
   `paid_events.positive?`. The full golden math (fee 1315c, free_events 1,
   paid_events 3, fixed_fee_total "6.0", per_unit_total "7.15") is pinned by
   the unit test `replays the invoice_percentage golden math` with
   Rails-shaped inputs (count 4, running_total limited to the first
   free_units_per_events values per SumService#running_total_per_events).
   The contract replay cannot feed those inputs — see finding 12.

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

12. **Aggregation seam starves the fee metadata (OPEN — M2).** The Aggregator
    (app/Services/Fees/ChargeService/Aggregator.php) reads the frozen
    `cached_aggregations` rows, which carry NO events count and NO per-event
    running total, so it reports `count = 1` and `running_total = [units]`:
    every fee shows `events_count: 1` (golden: 3 for graduated/package/volume,
    4 for percentage) and the percentage model's per-event branches cannot
    fire (paid_events 0, fixed fee 0 → invoice totals 5615 vs 6215). This is
    the M2 live-aggregation seam (BillableMetrics::Aggregations::* — sum,
    count and running_total come from the events store in Rails). The seam
    documents the gap in-code; do NOT fake count/running_total from the
    cached units. This is the ONLY remaining contract red.

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



### Normalizer slack (with unit tests in NormalizerTest)

- `token` fields → decoded JWT claims (both sides mint at the frozen clock).
- `lago_id` fields holding a UUID → `<uuid>`: rows created BY a captured
  request get a fresh uuid per runtime; everything else under `lago_id`
  (null, non-UUID) still compares strictly.
