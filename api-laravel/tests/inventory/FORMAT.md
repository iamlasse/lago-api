# Inventory artifact format

All inventory artifacts, the coverage ledger and milestone scope files live in
this directory. **Format: JSON** — chosen over YAML because the project may not
add composer dependencies for this (no symfony/yaml), PHP emits canonical JSON
natively, and drift detection needs byte-stable output. Everything here is
JSON; nothing is YAML. Do not mix formats.

## Canonical encoding

- `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, 2-space
  indent, plus a trailing newline.
- Rows sorted by `id` (byte order). No timestamps or volatile fields anywhere —
  `inventory:check` diffs committed bytes against a fresh regeneration, so any
  non-deterministic field would fail the check forever.

## Envelope

Every generated artifact (and the ledger) is one object:

```json
{
    "generator": "gen_graphql",
    "source": "schema.json (Rails introspection result)",
    "row_count": 387,
    "provisional": true,
    "warning": "…only on provisional artifacts…",
    "rows": [ ... ]
}
```

`generator` is a stable short name shared by every producer of the artifact
(the wrapper scripts, `gen-all.php`, and `inventory:check --update`), so any
producer's output is byte-identical. `provisional: true` appears only on
`rest.json` — the static route parse the plan forbids trusting permanently.
Every row inside it additionally carries `"provisional": true`.

## Row shapes

`graphql.json` — one row per Query/Mutation/Subscription field of the Rails
schema:

```json
{ "id": "gql:mutation:createCustomer", "kind": "mutation", "name": "createCustomer",
  "args": [{ "name": "attributes", "type": "CustomerInput!" }],
  "return": "Customer", "deprecated": false, "source": "schema.json" }
```

`serializers.json` / `services.json`:

```json
{ "id": "ser:V1.CustomerSerializer", "name": "V1::CustomerSerializer",
  "source": "app/serializers/v1/customer_serializer.rb" }
{ "id": "svc:Invoices.CalculateFeesService", "name": "Invoices::CalculateFeesService",
  "source": "app/services/invoices/calculate_fees_service.rb" }
```

`jobs.json` (queue is `queue_as`; `(dynamic)` when it resolves at enqueue time;
`unique`/`retry_on` stanzas are captured verbatim because their options are
contract-relevant):

```json
{ "id": "job:BillSubscriptionJob", "name": "BillSubscriptionJob",
  "source": "app/jobs/bill_subscription_job.rb", "queue": "billing",
  "unique": ["unique :until_executed, on_conflict: :log, lock_ttl: 12.hours"],
  "retry_on": [] }
```

`tables.json`:

```json
{ "id": "table:customers", "name": "customers", "source": "db/structure.sql",
  "columns": 21, "columns_hash": "…md5 of the ordered column list…",
  "partitioned": false }
```

`rest.json`:

```json
{ "id": "rest:GET:/api/v1/customers/:external_id", "verb": "GET",
  "path": "/api/v1/customers/:external_id", "handler": "api/v1/customers#show",
  "on": "member", "provisional": true,
  "source": "config/routes.rb (static parse — provisional)" }
```

## Row ids

`rest:<VERB>:<path>`, `gql:<query|mutation|subscription>:<field>`,
`ser:<dotted class>`, `svc:<dotted class>`, `job:<dotted class>`,
`table:<name>`. Job ids include the Ruby namespace (`job:Clock.SubscriptionsBillerJob`)
so same-named classes in different folders cannot collide; the plan's example
`job:BillSubscriptionJob` is the top-level case of the same rule.

## ledger.json

One row per inventory row (all artifacts joined). Seeded by `gen-all.php`;
afterwards it is **hand-maintained** — re-running `gen-all.php` refreshes
`source`, fills a missing `laravel`, seeds only brand-new ids, and drops ids
that vanished, but never touches your `status`/`tests`/`notes`.

```json
{ "id": "svc:Invoices.CalculateFeesService",
  "source": "app/services/invoices/calculate_fees_service.rb",
  "laravel": "App\\Services\\Invoices\\CalculateFeesService",
  "status": { "code": "done", "test": "written", "contract": "pass" },
  "tests": ["tests/Feature/Invoices/CalculateFeesServiceTest.php"],
  "notes": "…" }
```

- `laravel` — target FQCN per the plan's namespace mapping (projection, may
  not exist yet; `null` when nothing meaningful can be named).
- `status.code`: `todo | in_progress | done` — `done` requires its tests in
  the same PR (plan rule: tests travel with the code).
- `status.test`: `todo | ported | written` — `ported` = a Rails spec was
  ported; `written` = written-first.
- `status.contract`: `untested | pass` — goldens captured and replayed green.
- `tests` — repo-relative test paths; `inventory:check` asserts they exist.
  Plan convention: tests carry the row id as a Pest group
  (`->group('ledger:<row-id>')`).

## scope_mN.txt

Milestone gate input for `php artisan inventory:check --milestone=mN`: one row
id per line; blank lines and `#` comments ignored. Every listed row must meet
the gate (`code=done`, `test ∈ {ported, written}`, `contract=pass`, target
class exists for svc/ser/job, declared test files exist). Exit 0 only when the
whole file passes.
