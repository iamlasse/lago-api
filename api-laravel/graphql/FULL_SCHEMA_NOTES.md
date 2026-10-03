# Serving the full frozen schema — status

Goal: `POST /graphql` serves the entire frozen contract
(`graphql/frozen-schema.graphql`, byte-identical copy of Rails'
`schema.graphql`, md5 `b3769f7346aee6fc2284d24c9741bee0` — never edited).

## Current state: the full SDL IS served

`POST /graphql` now serves the whole frozen SDL through Lighthouse. All
former blockers are closed by `App\GraphQL\Providers\LagoSchemaServiceProvider`
(registered in `bootstrap/providers.php`):

1. **Explicit `schema { … }` block** — SOLVED. Lighthouse throws on
   `SchemaDefinitionNode`, so
   `App\GraphQL\Schema\Source\FrozenSchemaSourceProvider` (bound over
   Lighthouse's `SchemaStitcher`) serves the frozen file after a purely
   structural preprocessing: drop the `schema { … }` block, strip the two
   informational `@specifiedBy(url: …)` applications (Lighthouse has no
   handler and a shim would collide with graphql-php's built-in directive),
   and drop `type GraphqlSubscription { … }` (see 5).
2. **`@specifiedBy`** — SOLVED (stripped, see above).
3. **Every root field needs a resolver** — SOLVED.
   `App\GraphQL\Providers\LagoResolverProvider` (bound over Lighthouse's
   `ProvidesResolver`) returns a `null` resolver for any root field without a
   resolver class instead of failing the schema build (the agreed stub:
   nullable root fields resolve to plain null; non-null ones surface the
   standard GraphQL null violation as `Internal server error` with debug
   off). Fields with a resolver class resolve normally; the frozen SDL has no
   directives, so resolvers are found by Lighthouse's naming conventions:
   - root fields: `App\GraphQL\Queries\<StudlyField>` / `App\GraphQL\Mutations\<StudlyField>`
     (e.g. `organization` → `Queries\Organization`, `loginUser` → `Mutations\LoginUser`);
   - type fields: a class named after the TYPE in `App\GraphQL\Types\` with a
     method named after the FIELD (the direct analogue of Rails' `Types::*`
     object types — e.g. `Types\CurrentOrganization::apiKey`,
     `Types\User::premium`, which subclasses the pre-existing
     `UserType`/`MembershipType`).
   - nested fields without a type-class method fall back to a
     graphql-ruby-style attribute lookup (exact camelCase name, then
     snake_case) — necessary because the frozen SDL carries no `@rename`.

   ⚠ The "Subscription" NAME COLLISION (bit the subscriptions slice): Lighthouse
   treats every field whose parent type is literally named `Subscription` as a
   subscription-ROOT field — `FieldFactory::defaultResolver` routes them to
   `ProvidesSubscriptionResolver`, and `RootType::isRootType('Subscription')`
   is true. Two coordinated fixes (both in place):
   - `LagoSchemaServiceProvider` binds `ProvidesSubscriptionResolver` to a
     delegating shim that hands the field to the regular
     `LagoResolverProvider` (the real GraphQL subscription root is dropped
     from the served schema, so only the billing type's fields ever reach
     it);
   - `LagoResolverProvider` narrows its null-stub branch to the explicit
     `Query`/`Mutation` root names instead of `RootType::isRootType()`, so
     the billing type's fields fall through to the type-class methods and
     the attribute fallback.
4. **Interfaces & unions** — SOLVED (stubbed). Lighthouse resolves
   `interface AppliedTax`, `interface InvoiceItem` and the 5 unions via
   `App\GraphQL\Interfaces\{AppliedTax,InvoiceItem}` and
   `App\GraphQL\Unions\{ActivityLogResourceObject,Integration,
   IntegrationCustomer,Payable,PaymentProvider}`; they all extend
   `AbstractLagoTypeResolver`, whose `__invoke` throws an `ExecutionError`
   with extensions `{status: 500, code: "not_implemented"}`. Nothing
   produces these values yet, so the throw is effectively unreachable; when a
   resolver starts returning interface/union values, replace the stub with a
   real `resolveType`.
5. **Subscriptions** — PARTIALLY: no subscription root is served. Lighthouse
   names its subscription root `Subscription` by implicit naming, which
   collides with the schema's own `Subscription` OBJECT type (the billing
   subscription) — renaming the root would shadow it. `FrozenSchemaSourceProvider`
   therefore drops `type GraphqlSubscription` and `LagoSchemaBuilder`
   (`App\GraphQL\Schema\LagoSchemaBuilder`, bound over Lighthouse's
   `SchemaBuilder`) skips the subscription-root registration entirely; the
   no-op `ProvidesSubscriptionResolver` binding stays as the hook for the
   future subscriptions slice. This is the ONLY observable difference from
   Rails' introspection.

### Acceptance gate

`tests/Feature/GraphQL/IntrospectionDiffTest.php` diffs the LIVE schema
against `tests/fixtures/GraphQL/rails-schema-surface.json` (distilled from
the Rails repo's `schema.json`: 747 type names, 145 query root fields, 241
mutation root fields, 1 subscription field):

- type names: 746/747 match (only `GraphqlSubscription` whitelisted — see 5);
- query root fields: 145/145 match; mutation root fields: 241/241 match;
- implemented-operation regression guard (currentUser, organization,
  customer(s), apiKey(s), loginUser, updateOrganization, customer mutations,
  apiKey mutations) must never disappear.

## Resolvers implemented so far

- Queries: `currentUser`, `currentVersion`, `organization` (full
  `CurrentOrganization` computed-field set: apiKey, hmacKey, webhookUrl,
  emailSettings (wire `invoice_finalized`), eventsStore, featureFlags
  (filtered through `App\Support\FeatureFlag`), premiumIntegrations,
  authenticationMethods, authenticatedMethod, accessibleByCurrentSession,
  canCreateBillingEntity, billingConfiguration, timezone), `customer`,
  `customers` (filters + search + kaminari pagination via the
  `Customers\Query` port), `apiKey`, `apiKeys` (SanitizedApiKey masking
  `••••••••` + last 3, kaminari metadata),
  `subscription` (by `id` or `externalId`, the Rails external-id lookup
  ordering `terminated_at DESC NULLS FIRST, started_at DESC`),
  `subscriptions` (planCode/status/externalId/externalCustomerId/overriden/
  currency/billingEntityIds filters, search term,
  `exclude_next_subscriptions: true` semantics, kaminari pagination via the
  `Subscriptions\Query` port — `collection` + `metadata` shape),
  `invoice` (by id, `visible` statuses only — invisible statuses answer the
  `not_found` envelope), `invoices` (the full frozen-SDL filter set — amount
  range with `::numeric` casts, billingEntityIds, currency,
  customerExternalId/customerId, invoiceType, issuing-date range,
  paymentStatus, paymentDisputeLost, paymentOverdue, partiallyPaid,
  positiveDueAmount, purchaseOrderNumber (case-insensitive), selfBilled,
  status (visible-status intersection), subscriptionId — plus the
  `search_terms`/`number` search term with the UUID id escape hatch and the
  kaminari pagination through the `Invoices\Query` port).
- Type classes: `InvoiceCollectionMetadata` (the `BaseQuery::CappedTotalCount`
  port behind `invoices`: totalCount capped at 10 000, totalCountCapped,
  hasNextPage — exact even past the cap).
- Mutations: `loginUser` (input-wrapped), `updateOrganization`,
  `createCustomer`, `updateCustomer`, `destroyCustomer` (soft delete +
  `{id, clientMutationId}` payload), `createApiKey`, `updateApiKey`,
  `rotateApiKey`, `destroyApiKey` (backed by the new
  `App\Services\ApiKeys\{Create,Update,Rotate,Destroy}Service` ports and the
  `Customers\DestroyService` port; premium/license gating follows Rails),
  `createSubscription` (external id falls back to a UUID; missing
  customer/plan → the Rails `not_found` envelope), `updateSubscription`,
  `terminateSubscription` (`on_termination_*` behaviors forwarded Rails'
  `args.compact` style) — backed by the ported
  `Subscriptions\{Create,Update,Terminate}Service`,
  `finalizeInvoice` (the invoice is looked up among DRAFT invoices only, so
  unknown/non-draft ids answer `not_found`; backed by the ported
  `Invoices\FinalizeService` — Rails' RefreshDraftAndFinalizeService
  refresh + webhook/document tail land with the invoice-generation slice).
- Type classes: `Organization` / `CurrentOrganization` (subclass), `Customer`,
  `BillingEntity`, `SanitizedApiKey`, `User` (subclass of `UserType`),
  `Membership` (subclass of `MembershipType`),
  `Subscription` (status/billingTime enum names, nextPlan/previousPlan/
  nextName/nextSubscriptionType/nextSubscriptionAt/nextSubscription,
  downgradePlanDate, periodEndDate + currentBillingPeriod* via the
  `Subscriptions\DatesService`, usageThresholds `[]`, charges), `Invoice`
  (status/invoiceType/paymentStatus/taxStatus enum names, payableType,
  totalDueAmountCents/totalSettledAmountCents, paymentDisputeLosable,
  voidable, taxProviderVoidable (false until ErrorDetails land),
  associatedActiveWalletPresent (false until Wallets land),
  allChargesHaveFees/allFixedChargesHaveFees over the ported
  Charge/ChargeFilter/FixedCharge models, appliedTaxes (taxRate DESC),
  invoiceSubscriptions/subscriptions (the
  `order_by_subscription_invoice_name` sort), regeneratedInvoiceId).
- `App\GraphQL\Support\{Args,Page,TimezoneWire}`: snake_casing of wire args
  (graphql-ruby parity), the `collection` + `metadata` collection shape with
  kaminari defaults (page 1, limit 25), and the TimezoneEnum `TZ_*` wire
  mapping (generated from the frozen SDL; Rails' `Types::TimezoneEnum`
  behaviour).

## What remains (drives the rest of task 11)

0. **Invoice surface — blocked pieces** (all still on the null stub, each
   waiting on its feature slice):
   - Queries: `invoiceCreditNotes` (needs the CreditNote model + the
     `credit_notes` relation; Rails resolver = invoice.credit_notes.finalized
     ordered/paginated).
   - Mutations: `createInvoice`/`updateInvoice`/`deleteInvoice` (one-off
     invoice slice — `Invoices::CreateOneOffService`, `UpdateService`,
     `DeleteService` are unported; update/delete additionally reuse the
     `visible` lookup this slice built), `voidInvoice`
     (`Invoices::VoidService` + credit notes), `refreshInvoice`
     (`Invoices::RefreshDraftService`), `retryInvoice`, `downloadInvoice`/
     `downloadInvoiceXml` (attachments/ActiveStorage), `finalizeAllInvoices`,
     `retryAllInvoices`, `loseInvoiceDispute`, `resendInvoiceEmail`,
     `regenerateInvoice`, `retryInvoicePayment`, `retryAllInvoicePayments`,
     `fetchDraftInvoiceTaxes` (tax provider), `generatePaymentUrl`,
     `retryTaxProviderVoiding`, the `sync*IntegrationInvoice` payloads.
   - `Invoice` type fields that stay null/falsy via the fallback: metadata
     (InvoiceMetadata model unported), creditNotes, payments,
     errorDetails, activityLogs, fileUrl/xmlUrl (attachments), taxProviderId
     and the external*/integration* ids + integrationSyncable booleans
     (integration resources unported), availableToCreditAmountCents/
     creditableAmountCents/offsettableAmountCents/refundableAmountCents
     (non-null — null violation WHEN SELECTED, until credit notes land).
   - The `settlements` filter validates (only `credit_note` reaches the
     wire) but is NOT applied — the `invoice_settlements` table ships with
     the credit-notes slice; the `metadata` filter never arrives from the
     frozen wire.
   - The Rails finalize tax-provider branch (invoice → `pending` status with
     `taxStatus: pending` while taxes are pulled) is not ported — the
     ported FinalizeService finalizes straight away (Anrok integrations are
     unported).
1. **Root-field resolvers** — ~130 queries / ~230 mutations still resolve to
   null stubs; each slice lands its own `Queries\*` / `Mutations\*` classes
   (naming convention does the wiring — no SDL edits needed).
2. **Non-null computed type fields** — fields whose Rails implementation
   depends on unported features (e.g. `Customer.creditNotesBalances!`,
   `hasActiveWallet!`, `integrationCustomers!`, `Membership.permissions!`,
   `Membership.roles!`) resolve to null through the fallback and surface a
   null violation WHEN SELECTED. Each feature slice should add a type-class
   method (or accept the stub) as it lands. `Membership.permissions`/`roles`
   still need the Permission/roles port.
3. **Permission-gated fields** — Rails gates `apiKey`, `hmacKey`,
   `webhookUrl`, `billingConfiguration`, `emailSettings`, `taxes` and every
   resolver behind `REQUIRED_PERMISSION` (`CanRequirePermissions`). The
   permission port is pending; the resolvers currently enforce only
   AuthenticableApiUser + RequiredOrganization. When permissions land, add
   the checks in the resolvers (context carries `LagoContext::PERMISSIONS`).
4. **Interfaces/unions resolveType** — replace the `not_implemented` stubs
   with real resolution when the first resolver returns those values.
5. **Subscriptions** — ActionCable equivalent (broadcaster + the
   `aiConversationStreamed` field); un-drop the root in
   `FrozenSchemaSourceProvider` and remove the whitelist entry in the
   introspection diff when it lands.
6. **Customers filter contract** — Rails validates `customers` filters
   through `Queries::CustomersQueryFiltersContract` before querying; the
   GraphQL schema constrains most shapes, but the contract port is still
   open (`App\Services\Customers\Query::TODO`).
7. **Premium integrations on `createApiKey`/`rotateApiKey`** — mailers and
   ClickHouse security logs are TODO(port) inside the services.

## Regression notes for future slices

- `App\Models\Subscription::downgradePlanDate()` gates on the wrong
  subscription (`! $this->pending()` where Rails has
  `return unless next_subscription.pending?`), so an ACTIVE subscription
  with a pending downgrade resolves null from the model. The GraphQL
  `Types\Subscription::downgradePlanDate` reimplements the Rails logic to
  keep the wire correct — reconcile the model (models are owned by the
  models slice) and collapse the duplicate when fixed.
- Pint's `self_accessor` fixer rewrites an `App\Models\Invoice` type-hint to
  `self` inside ANY class named `Invoice` (the query and the type class are
  both named after the GraphQL field/type). Always import the model aliased
  (`use App\Models\Invoice as InvoiceModel;`) in `App\GraphQL\{Queries,
  Types, Mutations}\Invoice*` — same pattern as the `SubscriptionModel`
  alias in the subscriptions slice.
- Field names that collide with 0-arg/argful MODEL methods resolve through
  the Laravel "relation method" path (`terminatedAt(?timestamp)` on the
  model, for example, explodes as an accessor). Add a
  `App\GraphQL\Types\Subscription` method for such fields
  (`terminatedAt`, `nextSubscription` are done) — the type-class method wins
  over the attribute fallback.
- Unqualified column names in `Subscriptions\Query` break the moment a join
  exists (Postgres `ambiguous column`): the exclude-next-subscriptions LEFT
  JOIN and the plan/customer JOINs mean every subscription column in a WHERE
  must be `subscriptions.`-qualified.

- `bootstrap/cache/lighthouse-schema.php` caches the PREPROCESSED document
  AST. Delete it (or `artisan cache:clear`) after changing schema-side
  preprocessing; tests enable the cache (`APP_ENV=testing`).
- The old partial `graphql/schema.graphql` is retired — do not point
  `lighthouse.schema_path` back at it.
- Organization fixtures in tests must create the default billing entity
  explicitly (Rails does it in `Organizations::CreateService`; the
  frozen-schema port has no such hook) — service calls that touch
  `defaultBillingEntity` fail with `billing_entity not_found` otherwise.
