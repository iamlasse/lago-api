# Port Rails dev seeders (`api/db/seeds/*.rb`) to Laravel + `registerUser`

## Context

The front pointed at the Laravel port has no usable dev dataset: the stock
`DatabaseSeeder` creates `test@example.com` with **no membership**, so
`loginUser` always returns `incorrect_login_or_password` (resolver requires an
active membership — `app/GraphQL/Mutations/LoginUser.php:49`). `registerUser`
is one of the ~230 null-stub mutations, so signup is dead too. Upstream ships
12 dev seeders (`api/db/seeds/*.rb`, loaded alphabetically by `api/db/seeds.rb`)
that create the canonical `gavin@hooli.com` / `ILoveLago` login, the Hooli org
(fixed UUID `11111111-2222-3333-4444-555555555555`, api key
`lago_key-hooli-1234567890`), full catalog, demo customers/subs/wallets,
entitlements, alerts, invoices, events and quotes.

**User-approved scope:** port ALL 12 seeders; unported domains (entitlements,
usage alerts, quotes/order forms, product catalog, security/email clickhouse
logs) use **raw DB inserts** against the frozen schema (no new Eloquent
models); **implement `registerUser`**; **force premium on** during seeding
(mirror Rails `License.instance_variable_set(:@premium, true)`).

## Groundwork findings (verified)

1. **Premium drift trap**: `App\Services\BaseService::premium()` (line 84)
   reads `env('LAGO_LICENSE')` directly while `App\Support\License::premium()`
   reads `config('lago.license')`. Fix: make `BaseService::premium()` delegate
   to `License::premium()` (one line; the docblocks say they're both ports of
   the same Rails method).
2. **Dev-DB bootstrap**: `php artisan migrate --force` loads
   `database/frozen/structure.sql` (migration `0001_01_01_000000_load_frozen_schema.php`).
   The dev DB `lago` is currently empty — create + migrate before seeding.
3. Enum columns: integer-backed via Rails constant order
   (`recurring_transaction_rules`: interval/method/trigger/status), PG-native
   enums insert as strings (`quote_order_type`, `quote_status`,
   `order_form_status`, `entitlement_privilege_value_types`,
   `usage_monitoring_alert_types`, …). Verify each `\d <table>` while writing
   the raw-insert seeders.
4. `plan_overrides` gate exists in `Subscriptions\CreateService` but
   application is TODO(port) → seeder 02 intent-ports `Plans::OverrideService`
   inline (replicate plan, `parent_id`, name/description/amount_cents, copy
   charges). `started_at` param is ignored by the ported service → seeder
   force-updates `started_at`/`created_at` after creation.
5. `SendWebhookJob::WEBHOOK_SERVICES` has no `alert.triggered` → seeder 06
   must NOT enqueue webhooks (LogicException otherwise).
6. No `Organizations\CreateService` exists (only `BillingService`/`UpdateService`).
7. No `Permission::DATA` port → accountant permission list as a seeder-local
   const derived from Rails `config/permissions.yml` (finance ∪ manager keys,
   dotted form).
8. Service invocation convention: `X::callBang(args: [...], sendWebhook: false)`
   where the flag exists; failures → `Errors::resultError($result->getError())`.
9. Pint footguns from `graphql/FULL_SCHEMA_NOTES.md` apply; run
   `vendor/bin/pint --dirty --format agent` at the end.

## Files

| File | What |
|---|---|
| `database/seeders/DatabaseSeeder.php` | Replace: force premium (`config(['lago.license' => 'dev-seed-license'])`), call the 12 seeders in Rails order |
| `database/seeders/BaseSeeder.php` | 01_base port |
| `database/seeders/JohnDoeSeeder.php` | 02 port |
| `database/seeders/EntitlementsSeeder.php` | 05 port (raw, `License::premium()` gate) |
| `database/seeders/AlertingSeeder.php` | 06 port (raw, no webhook enqueue) |
| `database/seeders/SecurityLogsSeeder.php` | 07 — no-op stub (no ClickHouse/Kafka, no local tables) |
| `database/seeders/ProgressiveBillingSeeder.php` | 08 port (raw `usage_thresholds`) |
| `database/seeders/SubscriptionsSeeder.php` | 20 port (cust_1..5 + sub_1..5) |
| `database/seeders/EventsSeeder.php` | 21 port (Event model, 52 events) |
| `database/seeders/ProductCatalogSeeder.php` | 30 port (Hooli v2 org + catalog raw inserts) |
| `database/seeders/InvoicesSeeder.php` | 50 port (loops `Invoices\SubscriptionService::call`) |
| `database/seeders/EmailActivityLogsSeeder.php` | 60 — no-op stub |
| `database/seeders/OrderFormsSeeder.php` | 70 port (quotes/versions/order forms raw) |
| `app/GraphQL/Mutations/RegisterUser.php` | Port of Rails `Mutations::RegisterUser` + `UsersService#register` |
| `app/Services/Organizations/CreateService.php` | NEW: faithful minimal port of Rails `Organizations::CreateService` |
| `config/lago.php` | one line: `'signup_disabled' => (bool) env('LAGO_DISABLE_SIGNUP', false)` |
| `app/Services/BaseService.php` | one line: `premium()` delegates to `License::premium()` |
| `tests/Feature/GraphQL/RegisterUserMutationTest.php` | Pest, LoginUserTest conventions |
| `tests/Feature/Seeders/DatabaseSeederTest.php` | runs `db:seed` on test DB, asserts surface + login |

## Seeder mechanics (per Rails source of truth)

**BaseSeeder (01)**: `Role::firstOrCreate` (admin/finance/manager global,
`organization_id => null`); users gavin/dinesh pw `ILoveLago` (password cast →
`password_digest`); Hooli org via `Organization::factory()->create(['id' =>
'11111111-2222-3333-4444-555555555555', 'name' => 'Hooli'])` (factory brings
default billing entity + api key + webhook endpoint), then update
`premium_integrations` (port Rails' `PREMIUM_INTEGRATIONS` list as a const) +
`invoice_footer`; billing entity hooli + `EMAIL_SETTINGS`; memberships +
MembershipRole admin/finance; accountant role with seeder-local permission
const; anrok via raw `integrations` insert (type `anrok`, secrets json with two
uuids); api keys **destroyed + recreated each run** ('Expired Key' expired,
'Hooli Key' with value force-set via `DB::table('api_keys')->update(['value' =>
'lago_key-hooli-1234567890'])`); BMs sum_bm/count_bm via
`BillableMetrics\CreateService`; tax via `Taxes\CreateService`; add-ons
setup_fee/support_hour via `AddOn::factory` + AddOnTax join (port the intent of
Rails' second `setup_fee` gate bug, with comment); coupons via
`Coupons\CreateService`; plans standard_plan/premium_plan via
`Plans\CreateService::callBang(args: $params, sendWebhook: false)` (charges as
Rails param shapes, amount strings); pricing unit `xyz` raw insert.

**JohnDoeSeeder (02)**: customer `cust_john-doe` firstOrCreate (faker address
fields); `sub_john-doe-main` via `Subscriptions\CreateService` gated on active
sub, then force-update `started_at`/`created_at` = 6 months ago; main wallet via
`Wallets\CreateService` + raw `recurring_transaction_rules` insert (premium
rule: weekly/fixed/granted 10/1yr expiration/`transaction_metadata` origin
seeder/name "10 credits for free 🎁"); terminated wallet via `Wallet::create`;
one-off invoice via `Invoices\CreateOneOffService` (2 × setup_fee, tax code,
timestamp +5d, skipPsp) — un-gated like Rails; credit note 4800c via
`CreditNotes\CreateService` (items: fee 4000c); second sub
`sub_john-doe-main-2` with inline `Plans::OverrideService` intent-port.

**EntitlementsSeeder (05)**: raw inserts; `clean_up_feature!` delete-then-
recreate semantics per feature (removals/values/entitlements/privileges,
ignoring `deleted_at`); features seats (privileges max/max_admins/root with
staggered `created_at`; plan values max=20, max_admins 3000 soft-deleted → 3;
sub values max=99/root=true), analytics_api, acls (soft-deleted), salesforce
(sub-only), premium_support (+removal), sso (select privilege with
`select_options` config; plan "okta", sub "google", soft-deleted removal).
premium-gated.

**AlertingSeeder (06)**: cleanup delete triggered→thresholds→alerts for
`sub_john-doe-main`; alerts `default` (warn 8000/alert 10000/panic 3300
recurring), premium `total` (info 100000), BM alert `ops` on sum_bm (5000,
1000 recurring); triggered alerts rows with `crossed_thresholds` jsonb exactly
as Rails (2mo/11d/4d ago). No webhook dispatch (TODO(port) comment).

**SecurityLogsSeeder (07) / EmailActivityLogsSeeder (60)**: no-op stubs with
header comment (ClickHouse/Kafka absent; no local tables).

**ProgressiveBillingSeeder (08)**: delete+insert `usage_thresholds`: plan
12000 'Initial Threshold', 100000 'Recurring Threshold' recurring; sub 40000,
80000 (no name), 200000 recurring.

**SubscriptionsSeeder (20)**: cust_1..5 + sub_1..5 via
`Customer::firstOrCreate` / `Subscription::firstOrCreate`, active, calendar,
6 months ago, faker noise fields.

**EventsSeeder (21)**: `Event::create` helper — `tr_` + hex, properties
`{custom_field: 10}`, metadata UA/ip; 6 month-offsets × (5 sum + 2 count),
5 blanked-properties, 5 `code='foo'`.

**ProductCatalogSeeder (30)**: Hooli v2 org (id `33333333-4444-5555-6666-777777777777`,
api key `lago_key-hooli-v2-1234567890` forced), `feature_flags` += product_catalog;
BM `catalog_api_calls` via service + `BillableMetricFilter` model (region
us/eu); raw inserts for `product_categories`/`products`/`product_filters`/
`rate_cards`/`rate_card_rates`/`catalog_plans`/`plan_rate_cards` (verify the
filter-values table existence during implementation; comment if absent).

**InvoicesSeeder (50)**: only when `Invoice::count() === 0`; per subscription,
`invoice_count = round((now - subscription_at) / 1.month)`; loop
`Invoices\SubscriptionService::call(subscriptions: [$sub], timestamp: …,
invoicingReason: 'subscription_periodic')` — `.call` (non-bang), failures
swallowed like Rails.

**OrderFormsSeeder (70)**: raw inserts; `number = QT-{year}-{seq %04d}` /
`OF-…`, per-scope `sequential_id = MAX+1`; john-doe quote chain (2 owners
gavin+dinesh, 3 versions: 2 voided + 1 draft current), draft one_off quotes
for cust_1..5, six approved quote+version+order-form combos (generated /
signed / expired / voided / generated+expires 7d). Verify whether
`quotes.current_version_id` exists in the frozen schema; skip with comment if
absent.

## registerUser design

`App\GraphQL\Mutations\RegisterUser` (auto-wired by naming convention, no SDL
edits), following LoginUser's style; side effects (Segment, security log,
UserDevices) deferred with docblock notes:

1. Unwrap `input`; read `email`/`password`/`organizationName`.
2. `config('lago.signup_disabled')` → `Errors::notAllowedError('signup_disabled')` (405).
3. Email sanitization port (invisible chars stripped, dash lookalikes → `-`, trim).
4. `User::where('email')->exists()` → `validationError(['email' => ['user_already_exists']])`.
5. Rails validations replicated (email blank; password length 6..72 with
   has_secure_password message strings — verify exact strings from Rails).
6. `DB::transaction`: `User::create` → new `Organizations\CreateService`
   (org + api key + default billing entity with `id = org.id`,
   `code = Str::slug(name, '_')`, document_numbering per-organization →
   per-billing-entity on the entity) → `Membership` (status active) →
   `MembershipRole` with global admin role → `AuthToken::encode` (null →
   `executionError('Internal Error', 500, 'token_encoding_error')`).
7. Return `['membership' => …, 'organization' => …, 'token' => …, 'user' => …]`
   — matches frozen `RegisterUser` payload (membership/organization/token/user).

## Idempotency

Natural-key `firstOrCreate` everywhere Rails uses `find_or_create_by!`;
code-gated service calls for catalog entities; raw-insert entities gate on
`doesntExist()`; 05/06 delete-then-recreate (Rails does this deliberately);
api keys recreated every run (Rails semantics). Known Rails-inherited
non-idempotency preserved with comments: 02's one-off invoice + credit note,
21's events.

## Verification

1. `docker exec lago-laravel-pg psql -U postgres -c "CREATE DATABASE lago;"`
   (if absent); `DB_DATABASE=lago` in `.env`; `php artisan migrate --force`.
2. `php artisan db:seed` — run **twice** (idempotency). Check Hooli org UUID,
   `api_keys.value = lago_key-hooli-1234567890`, `memberships` count ≥ 2.
3. Smoke `loginUser` over HTTP: `gavin@hooli.com` / `ILoveLago` → token + user.
4. `vendor/bin/pest tests/Feature/GraphQL/RegisterUserMutationTest.php
   tests/Feature/Seeders/DatabaseSeederTest.php`, then the user runs the full
   suite (`php artisan test --compact`).
5. `vendor/bin/pint --dirty --format agent`.

Implementation order: BaseService/config lines → RegisterUser +
Organizations\CreateService + test → BaseSeeder/JohnDoe/20/21 + seeder test →
raw-insert seeders 05/06/08/30/70 → stubs 07/60 → full idempotency re-run.
