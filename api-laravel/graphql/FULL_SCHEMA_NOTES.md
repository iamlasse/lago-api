# Serving the full frozen schema — blocker notes

Goal: `POST /graphql` serves the entire frozen contract
(`graphql/frozen-schema.graphql`, byte-identical copy of Rails'
`schema.graphql`, md5 `b3769f7346aee6fc2284d24c9741bee0` — never edited).

## Current state

Lighthouse serves a **partial schema** (`graphql/schema.graphql`) containing:

- the 7 custom scalars: `BigInt` (string serialization),
  `ISO8601DateTime` / `ISO8601Date` (UTC `…Z`), `JSON` (pass-through),
  `ObfuscatedString` (`••••••••…xyz` masking, port of
  `Types::ObfuscatedStringType`), `ChargeFilterValues` (pass-through),
  `HttpStatus` (validated 100..599 integer) — `app/GraphQL/Scalars/*`
- `Query.currentUser` (guard: `@lagoAuth`) and `Query.currentVersion`
- `Mutation.loginUser` with `LoginUserInput` / `LoginUser`
- the minimum referenced types, copied field-for-field from the frozen SDL:
  `User` (verbatim field set), `Membership` (trimmed, see below),
  `Organization` (trimmed, see below), `CurrentVersion`, `MembershipStatus`

## Blockers to serving `frozen-schema.graphql` verbatim

Serving was attempted (swap `lighthouse.schema_path` to the frozen file,
`php artisan lighthouse:validate-schema`). Each attempt fails at a different
layer; none of the fixes can be made without editing the frozen file or
registering service providers (out of this slice's scope).

1. **Explicit `schema { … }` block** (frozen-schema.graphql:1-5):
   Lighthouse's `DocumentAST` throws `Unknown definition type:
   GraphQL\Language\AST\SchemaDefinitionNode`. Lighthouse only infers the
   root types from implicit naming (`Query`, `Mutation`, `Subscription`).
   Required preprocessing: drop the 5-line block (harmless — `query: Query`
   and `mutation: Mutation` match implicit names) **but** the subscription
   root is `subscription: GraphqlSubscription`, which Lighthouse would no
   longer recognize (it requires the type to be literally `Subscription`),
   so a rename would be needed too.

2. **`@specifiedBy(url: …)`** on `ISO8601Date` / `ISO8601DateTime`
   (frozen-schema.graphql:7547, 7552): Lighthouse has no handler for the
   directive ("No directive found for `specifiedBy`"), and a shim directive
   class is impossible because Lighthouse merges document directive
   definitions with graphql-php's built-in `specifiedBy` without dedup
   (`SchemaBuilder` line 92: `array_merge(GraphQL::getStandardDirectives(),
   $directives)` → "Directive @specifiedBy defined multiple times").
   Required preprocessing: strip the two applications (informational only).

3. **Every root field needs a resolver** — the hard blocker. Lighthouse's
   `ResolverProvider` throws at schema-build time for any `Query`/`Mutation`
   field without a resolver directive or class ("Could not locate a field
   resolver for the query field \"activityLog\""). The frozen SDL declares
   ~200 query root fields and ~150 mutations; Rails resolves them through
   `Resolvers::*` / `Mutations::*` classes that are ported incrementally.
   Lighthouse has no "resolve to null / not-yet-implemented" fallback.
   The clean fix: bind a custom `Nuwave\Lighthouse\Support\Contracts\ProvidesResolver`
   that returns a `null` resolver (or a `not_implemented` error field) when no
   resolver class exists yet — needs a service provider registration
   (`bootstrap/providers.php`), which is outside this slice's ownership.

4. **Interfaces & unions**: `interface AppliedTax` and `interface InvoiceItem`
   (2 interfaces), plus 5 unions (`ActivityLogResourceObject`, `Integration`,
   `IntegrationCustomer`, `Payable`, `PaymentProvider`) require Lighthouse
   `App\GraphQL\Interfaces\*` / `App\GraphQL\Unions\*` resolver classes to
   resolve concrete types at execution. Not yet ported.

5. **Subscriptions**: the frozen schema's root type is `GraphqlSubscription`;
   Lighthouse requires the `Nuwave\Lighthouse\SubscriptionServiceProvider` to
   be registered (Rails uses ActionCable — the Laravel equivalent is a
   separate slice).

6. **Trimmed types in the partial schema** (rejoin once ports land):
   - `Membership.permissions: Permissions!` and `Membership.roles: [String!]!`
     need the `Permission` port (`config/permissions.yml` driven) and the
     roles tables/models.
   - `Organization`: only `id`, `name`, `slug` are exposed; the frozen type
     also has `accessibleByCurrentSession`, `billingConfiguration`,
     `canCreateBillingEntity`, `defaultCurrency`, `logoUrl`, `timezone`.
   - `User.premium` resolves `false` (Rails `License.premium?`; license port
     pending).
   - Scalars keep `@specifiedBy`-less declarations (see blocker 2).

## Resolution path

After M1 tasks land enough resolvers, revisit with: a small service provider
that (a) registers the subscription provider or a stub, (b) binds a
null-fallback `ProvidesResolver`, (c) preprocesses the frozen SDL in a
documented, verified step (drop schema block, strip `@specifiedBy`) inside a
`@see`-verified script rather than editing the frozen file. Introspection-diff
against `schema.json` then becomes the acceptance gate.
