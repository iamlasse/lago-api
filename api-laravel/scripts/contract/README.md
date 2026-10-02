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

2. **`PUT /api/v1/organizations` echoes a fresh webhook endpoint; Rails
   echoes the stale association.** With no pre-existing endpoint, Rails'
   UpdateService does `webhook_endpoints.first_or_initialize` + `update!`
   (endpoint IS persisted — verify in `lago_rails_golden`), but the response
   serializes the already-loaded association: golden shows
   `webhook_url: ""`, `webhook_urls: []`. Laravel shows the new URL.
   Diff: `$.organization.webhook_url` — golden `""`, Laravel the URL;
   `$.organization.webhook_urls` — golden `[]`, Laravel 1 item.
