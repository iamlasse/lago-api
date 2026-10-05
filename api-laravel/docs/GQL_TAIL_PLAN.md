# GraphQL Long Tail — Dependency-Ordered Slice Plan

Scoping doc for the ~350 open `gql:` ledger rows. Generated from
`tests/inventory/ledger.json` (328 open `gql:` rows: 200 mutations, 127 queries,
1 subscription) cross-checked against the live Laravel tree (`app/GraphQL/**`,
`app/Services/**`, `app/Queries/**`, `tests/Feature/GraphQL/**`) and the frozen
Rails source (`/tmp/lago-api-exploration/app/graphql/{resolvers,mutations}`).

**Wiring model** (from `graphql/FULL_SCHEMA_NOTES.md`): the full frozen SDL is
served; root fields resolve by naming convention from
`App\GraphQL\Queries\<Field>` / `App\GraphQL\Mutations\<Field>`; unresolved root
fields null-stub. So closing a row = (resolver class) + (service behind it) +
(GraphQL feature test) + ledger flip.

## Summary numbers

| Bucket | Rows | Meaning |
|---|---|---|
| Open `gql:` rows | **328** | |
| Stale-unflipped (code + test already exist, ledger says todo) | **54** | flip after verify — zero code |
| Code-done, test-gap (resolver exists, no GraphQL test yet) | **21** | small test PRs |
| Ready now (all services/models ported, need resolver + test) | **185** | Arcs 1–3 |
| Customer portal (needs portal auth guard first) | **15** | Arc 4 |
| Blocked on unported domains | **53** | Backlog |
| N/A (cannot ever pass against the served schema) | **1** | `gql:subscription:aiConversationStreamed` |

Ready-now total incl. portal: 200 of 328 (61%). With the stale rows flipped,
effective open tail drops to ~253, of which only 53 are genuinely blocked.

Earlier "blocked on payment providers pt 2 / dunning / entitlements" rows are
**no longer blocked**: `app/Services/PaymentProviders/{Adyen,Cashfree,Flutterwave,Gocardless,Moneyhash,Stripe}`,
`app/Services/PaymentRequests`, `app/Services/PaymentProviderCustomers`,
`app/Services/DunningCampaigns` and `app/Services/Entitlements` are all landed
— those rows are folded into the ready-now arcs below.

---

## Slice 0 — Ledger reconciliation (do first, effort S, no code)

**Status: done (2026-10-05).** The 0a rows are flipped except the five
invoice mutations (`deleteInvoice`/`downloadInvoice`/`refreshInvoice`/
`retryInvoice`/`voidInvoice`) — the plan's stale-unflipped claim was wrong for
those five: they are deliberate null stubs (see the stub-semantics group in
`InvoiceMutationsTest`), so they stay open. All 0b rows are closed: tests were
added (quotes/order-form fetches + updateQuote/clone/void/executeOrder, plan,
createPlan, billing entities, subscriptionEntitlement, createBillableMetric,
pricing units) and `PricingUnits/UpdateService` + the `UpdatePricingUnit`
resolver were ported (the one real gap). The row audit also fixed a real
contract bug the new billing-entity tests caught: `BillingEntity.isDefault`
had no resolver (Rails: `organization.default_billing_entity&.id == id`).
`gql:subscription:aiConversationStreamed` is marked N/A in the ledger.

### 0a. Stale-unflipped — 54 rows, code + GraphQL test both exist

Every row below has its resolver class in `app/GraphQL/**` **and** is exercised
by an existing test in `tests/Feature/GraphQL/` (test file in parentheses).
Action: re-run the suite, flip `status.code`/`status.test` in the ledger.

- Add-ons — `AddOnsTest`: `gql:query:addOn`, `gql:query:addOns`,
  `gql:mutation:createAddOn`, `gql:mutation:updateAddOn`, `gql:mutation:destroyAddOn`
- Coupons — `CouponsMutationsTest` + `CouponsResolverTest`:
  `gql:query:coupon`, `gql:query:coupons`, `gql:query:appliedCoupons`,
  `gql:mutation:createCoupon`, `gql:mutation:updateCoupon`,
  `gql:mutation:destroyCoupon`, `gql:mutation:terminateCoupon`,
  `gql:mutation:createAppliedCoupon`, `gql:mutation:terminateAppliedCoupon`
- Dunning campaigns — `DunningCampaignsTest`: `gql:query:dunningCampaign`,
  `gql:query:dunningCampaigns`, `gql:mutation:createDunningCampaign`,
  `gql:mutation:updateDunningCampaign`, `gql:mutation:destroyDunningCampaign`
- Features + entitlements — `FeaturesTest`: `gql:query:feature`,
  `gql:query:features`, `gql:mutation:createFeature`, `gql:mutation:updateFeature`,
  `gql:mutation:destroyFeature`, `gql:mutation:createOrUpdateSubscriptionEntitlement`,
  `gql:mutation:removeSubscriptionEntitlement`, `gql:query:subscriptionEntitlements`
- Wallets — `WalletsMutationsTest` + `WalletsResolverTest`:
  `gql:query:wallet`, `gql:query:wallets`, `gql:query:walletTransaction`,
  `gql:query:walletTransactions`, `gql:mutation:createCustomerWallet`,
  `gql:mutation:updateCustomerWallet`, `gql:mutation:terminateCustomerWallet`,
  `gql:mutation:createCustomerWalletTransaction`
- Integrations (in-flight staged slice, flip when merged — see git index):
  `gql:mutation:createHubspotIntegration`, `gql:mutation:updateHubspotIntegration`,
  `gql:mutation:createSalesforceIntegration`, `gql:mutation:updateSalesforceIntegration`,
  `gql:mutation:createNetsuiteIntegration`, `gql:mutation:updateNetsuiteIntegration`,
  `gql:mutation:createXeroIntegration`, `gql:mutation:updateXeroIntegration`,
  `gql:mutation:destroyIntegration` (`CrmIntegrationsMutationsTest`,
  `AccountingIntegrationsMutationsTest`, `IntegrationsMutationsTest`)
- Payment receipts — `PaymentReceiptsTest`: `gql:query:payment`,
  `gql:mutation:downloadPaymentReceipt`, `gql:mutation:downloadXmlPaymentReceipt`,
  `gql:mutation:resendPaymentReceiptEmail`
- Invoices — `InvoiceMutationsTest`: `gql:mutation:deleteInvoice`,
  `gql:mutation:downloadInvoice`, `gql:mutation:refreshInvoice`,
  `gql:mutation:retryInvoice`, `gql:mutation:voidInvoice`
- Plans — `PlansTest` (states "Ledger rows: gql:query:plans" in its docblock):
  `gql:query:plans`

### 0b. Code-done, test-gap — 21 rows (resolver exists, no GraphQL test)

Add small `tests/Feature/GraphQL/*Test.php` cases, then flip:

- Quotes / order forms — `QuotesAndOrderFormsTest` covers create/approve/update-version
  but not these: `gql:mutation:updateQuote`, `gql:mutation:cloneQuoteVersion`,
  `gql:mutation:voidQuoteVersion`, `gql:mutation:executeOrder`,
  `gql:query:quote`, `gql:query:quoteVersion`, `gql:query:order`,
  `gql:query:orderForm`, `gql:query:orders`
- `gql:query:plan` (landed in f1c4995), `gql:mutation:createPlan` (service tests
  exist, no GQL test)
- Pricing units: `gql:mutation:createPricingUnit`, `gql:mutation:updatePricingUnit`¹,
  `gql:query:pricingUnit`, `gql:query:pricingUnits`
  ¹ DONE — `PricingUnits/UpdateService` ported from Rails
  `services/pricing_units/update_service.rb` with the `UpdatePricingUnit`
  resolver (2026-10-05).
- Billing entities: `gql:mutation:createBillingEntity`, `gql:mutation:updateBillingEntity`,
  `gql:mutation:destroyBillingEntity`, `gql:query:billingEntity`, `gql:query:billingEntities`
- `gql:query:subscriptionEntitlement` (FeaturesTest covers only the plural),
  `gql:mutation:createBillableMetric`

---

## Arc 1 — Catalog & pricing long tail (ready now, effort L total, mechanically parallel)

69 rows. Every service behind these is already ported; work is thin
`Mutations/Queries` classes (pattern: `app/GraphQL/Mutations/CreateAddOn.php`)
+ tests. Only two service gaps (noted).

| Cluster | Rows | Rails sources | Laravel deps |
|---|---|---|---|
| Taxes (9) | `gql:query:tax` `gql:query:taxes` `gql:mutation:createTax` `gql:mutation:updateTax` `gql:mutation:destroyTax` `gql:query:billingEntityTaxes` `gql:mutation:billingEntityApplyTaxes` `gql:mutation:billingEntityRemoveTaxes` `gql:mutation:billingEntityUpdateAppliedDunningCampaign` | `mutations/taxes`, `mutations/billing_entities/{apply_taxes,remove_taxes,update_applied_dunning_campaign}`, `resolvers/{tax,taxes,billing_entity_taxes}` | `Services/Taxes/*`, `Services/BillingEntities/Taxes/*` — all present |
| Billable metrics (4) | `gql:query:billableMetric` `gql:query:billableMetrics` `gql:mutation:updateBillableMetric` `gql:mutation:destroyBillableMetric` | `resolvers/{billable_metric,billable_metrics}`, `mutations/billable_metrics` | `Services/BillableMetrics/*` present; `CreateBillableMetric.php` resolver exists (row in 0b) |
| Products (15) | `gql:query:product` `gql:query:products` `gql:query:productCategory` `gql:query:productCategories` `gql:query:productFilter` `gql:query:productFilters` `gql:mutation:createProduct` `gql:mutation:updateProduct` `gql:mutation:destroyProduct` `gql:mutation:createProductCategory` `gql:mutation:updateProductCategory` `gql:mutation:destroyProductCategory` `gql:mutation:createProductFilter` `gql:mutation:updateProductFilter` `gql:mutation:destroyProductFilter` | `mutations/{products,product_categories,product_filters}`, matching resolvers | `Services/Products`, `ProductCategories`, `ProductFilters` + `app/Queries/*Query` — present |
| Rate cards (13) | `gql:query:rateCard` `gql:query:rateCards` `gql:query:rateCardRate` `gql:query:rateCardRates` `gql:mutation:createRateCard` `gql:mutation:updateRateCard` `gql:mutation:destroyRateCard` `gql:mutation:createRateCardRate` `gql:mutation:updateRateCardRate` `gql:mutation:destroyRateCardRate` `gql:mutation:createRatePhase` `gql:mutation:updateRatePhase` `gql:mutation:destroyRatePhase` | `mutations/{rate_cards,rate_card_rates,rate_phases}`, `resolvers/rate_card*` | `Services/RateCards`, `RateCardRates`, `RatePhases` — present |
| Applied rate cards + contracts (11) | `gql:query:planAppliedRateCards` `gql:query:contractAppliedRateCards` `gql:query:contract` `gql:query:contracts` `gql:mutation:createPlanAppliedRateCard` `gql:mutation:destroyPlanAppliedRateCard` `gql:mutation:createContractAppliedRateCard` `gql:mutation:destroyContractAppliedRateCard` `gql:mutation:createContract` `gql:mutation:updateContract` `gql:mutation:terminateContract` | `mutations/{plan_applied_rate_cards,contract_applied_rate_cards,contracts}`, `resolvers/{contract,contracts,contract_applied_rate_cards,plan_applied_rate_cards}` | `Services/PlanRateCards`, `ContractRateCards`, `Contracts` — present |
| Plan mutations (3) | `gql:mutation:updatePlan` `gql:mutation:destroyPlan` `gql:mutation:updatePricingUnit` | `mutations/{plans,pricing_units}` | `Services/Plans/{Update,Destroy}` present; `PricingUnits/UpdateService` + `UpdatePricingUnit` resolver landed (2026-10-05) |
| Charges (14) | `gql:mutation:createCharge` `gql:mutation:updateCharge` `gql:mutation:destroyCharge` `gql:mutation:createChargeFilter` `gql:mutation:updateChargeFilter` `gql:mutation:destroyChargeFilter` `gql:mutation:createFixedCharge` `gql:mutation:updateFixedCharge` `gql:mutation:destroyFixedCharge` `gql:mutation:updateSubscriptionCharge` `gql:mutation:updateSubscriptionFixedCharge` `gql:mutation:createSubscriptionChargeFilter` `gql:mutation:updateSubscriptionChargeFilter` `gql:mutation:destroySubscriptionChargeFilter` | `mutations/{charges,charge_filters,fixed_charges}`, `mutations/subscriptions/{create_charge_filter,destroy_charge_filter,update_charge_filter,update_charge,update_fixed_charge}` | `Services/Charges`, `FixedCharges`, `Charges/OverrideService`, `FixedCharges/OverrideService` present; **gap:** `Services/ChargeFilters` only has `CreateOrUpdateBatchService` — split out per-id create/update/destroy (S) |

---

## Arc 2 — Payments, invoices & credit notes tail (ready now, effort L)

45 rows.

| Cluster | Rows | Rails sources | Laravel deps / gaps |
|---|---|---|---|
| Payments surface (28) | `gql:query:payments` `gql:query:paymentMethods` `gql:query:paymentRequests` `gql:query:paymentProvider` `gql:query:paymentProviders` `gql:mutation:createPayment` `gql:mutation:createPaymentRequest` `gql:mutation:destroyPaymentMethod` `gql:mutation:setPaymentMethodAsDefault` `gql:mutation:setPaymentProviderCustomerAsDefault` `gql:mutation:createPaymentProviderCustomer` `gql:mutation:updatePaymentProviderCustomer` `gql:mutation:destroyPaymentProviderCustomer` `gql:mutation:destroyPaymentProvider` `gql:mutation:addStripePaymentProvider` `gql:mutation:updateStripePaymentProvider` `gql:mutation:addAdyenPaymentProvider` `gql:mutation:updateAdyenPaymentProvider` `gql:mutation:addCashfreePaymentProvider` `gql:mutation:updateCashfreePaymentProvider` `gql:mutation:addFlutterwavePaymentProvider` `gql:mutation:updateFlutterwavePaymentProvider` `gql:mutation:addGocardlessPaymentProvider` `gql:mutation:updateGocardlessPaymentProvider` `gql:mutation:addMoneyhashPaymentProvider` `gql:mutation:updateMoneyhashPaymentProvider` `gql:mutation:generateCheckoutUrl` `gql:mutation:generatePaymentUrl` | `mutations/payment_providers/*` (6 providers × add/update + destroy), `mutations/{payment_methods,payment_provider_customers,payments,payment_requests}`, `resolvers/{payment_providers,payment_provider,payment_methods,payments,payment_requests}` | `Services/PaymentProviders/*`, `PaymentProviderCustomers/*`, `PaymentMethods/*`, `Payments/ManualCreateService`, `PaymentRequests/CreateService`, `Invoices/Payments/GeneratePaymentUrlService` — present. **Gap:** port `Customers::GenerateCheckoutUrlService` for `generateCheckoutUrl` (S) |
| Invoices (13) | `gql:mutation:createInvoice` `gql:mutation:updateInvoice` `gql:mutation:finalizeAllInvoices` `gql:mutation:retryAllInvoices` `gql:mutation:retryInvoicePayment` `gql:mutation:retryAllInvoicePayments` `gql:mutation:loseInvoiceDispute` `gql:mutation:downloadInvoiceXml` `gql:mutation:resendInvoiceEmail` `gql:mutation:fetchDraftInvoiceTaxes` `gql:mutation:updateCustomerInvoiceGracePeriod` `gql:query:creditNotes` `gql:query:customerInvoices` | `mutations/invoices/*`, `mutations/customers/update_invoice_grace_period`, `resolvers/{credit_notes,invoices}` + customer nested invoices | `Services/Invoices/{CreateOneOff,Update,Finalize,Retry,LoseDispute,GenerateXml}` , `Invoices/Payments/*`, `Invoices/ProviderTaxes`, `Emails/ResendService` — present. **Gaps:** grace-period service (S); `retryAllInvoices` batch semantics per Rails `invoices/retry_all` |
| Credit notes (3) | `gql:mutation:downloadCreditNote` `gql:mutation:downloadXmlCreditNote` `gql:mutation:resendCreditNoteEmail` | `mutations/credit_notes/{download,download_xml,resend_email}` | CreditNote CRUD landed; **gap:** `Services/CreditNotes/{GeneratePdf,GenerateXml}` (clone the `Invoices` pattern + Gotenberg call) and email resend (S/M) |
| Orders (1) | `gql:mutation:updateOrder` | `mutations/orders/update` | `Services/Orders/UpdateService` present |

---

## Arc 3 — Platform tail: team/webhooks/integrations + analytics + usage (ready now, effort L, three parallel sub-clusters)

71 rows.

### 3a. Team, webhooks, integrations (44)

- **Invites / memberships / roles / auth (18)**: `gql:mutation:acceptInvite`
  `gql:mutation:createInvite` `gql:mutation:revokeInvite` `gql:mutation:updateInvite`
  `gql:query:invite` `gql:query:invites` `gql:query:memberships`
  `gql:mutation:revokeMembership` `gql:mutation:updateMembership`
  `gql:mutation:createRole` `gql:mutation:updateRole` `gql:mutation:destroyRole`
  `gql:query:role` `gql:query:roles` `gql:mutation:createPasswordReset`
  `gql:mutation:resetPassword` `gql:query:passwordReset` `gql:query:googleAuthUrl`
  — Rails `mutations/{invites,memberships,roles,password_resets}`,
  `resolvers/{invite,invites,memberships,roles,password_reset}`.
  Laravel gaps (all S): port `Services/Invites/{Create,Revoke,Update}` (only
  `AcceptService` exists), `Services/Memberships/{Revoke,Update}`,
  `Services/Roles/{Create,Update,Destroy}`, `Services/PasswordResets/{Create,Reset}`
  (+ password-reset mailer). `Auth/GoogleService` exists for `googleAuthUrl`.
- **Webhooks (8)**: `gql:query:webhook` `gql:query:webhooks`
  `gql:query:webhookEndpoint` `gql:query:webhookEndpoints`
  `gql:mutation:createWebhookEndpoint` `gql:mutation:updateWebhookEndpoint`
  `gql:mutation:destroyWebhookEndpoint` `gql:mutation:retryWebhook`
  — Rails `mutations/webhook_endpoints`, `mutations/webhooks`; Laravel
  `Services/Webhooks/Endpoints/*` + `RetryService` present, `WebhookEndpointsQuery`
  present. Pure resolver work (S).
- **Integrations (18)**: `gql:mutation:createIntegrationCustomer`
  `gql:mutation:updateIntegrationCustomer` `gql:mutation:destroyIntegrationCustomer`
  `gql:mutation:setIntegrationCustomerAsDefault` `gql:query:integration`
  `gql:query:integrations` `gql:query:integrationSubsidiaries`
  `gql:query:integrationItems` `gql:mutation:fetchIntegrationAccounts`
  `gql:mutation:fetchIntegrationItems` `gql:mutation:syncIntegrationInvoice`
  `gql:mutation:syncIntegrationCreditNote` `gql:mutation:syncHubspotIntegrationInvoice`
  `gql:mutation:syncSalesforceInvoice` `gql:mutation:createEntraIdIntegration`
  `gql:mutation:updateEntraIdIntegration` `gql:mutation:createOktaIntegration`
  `gql:mutation:updateOktaIntegration`
  — Rails `mutations/integration_customers`, `mutations/integrations/{hubspot,salesforce,netsuite,xero,entra_id,okta,sync_invoice,sync_credit_note}`,
  `mutations/integration_items`, `resolvers/{integration,integrations,integration_items,integration_subsidiaries...}`.
  Laravel: `Services/IntegrationCustomers/{Create,Update}` present (gaps:
  Destroy + SetAsDefault, S); `Services/Integrations/Aggregator` (sync/fetch)
  just landed; `Models/Integrations/{EntraId,Okta}Integration` present — the
  SSO integration CRUD services are thin (S each).

### 3b. Analytics, data API, usage, events, alerts (27)

All backend services already ported — resolver + test only.

- Analytics (5): `gql:query:grossRevenues` `gql:query:invoiceCollections`
  `gql:query:invoicedUsages` `gql:query:mrrs` `gql:query:overdueBalances`
  — `Services/Analytics/{GrossRevenues,InvoiceCollections,InvoicedUsages,Mrrs,OverdueBalances}` present.
- Data API (9): `gql:query:dataApiMrrs` `gql:query:dataApiMrrsPlans`
  `gql:query:dataApiPrepaidCredits` `gql:query:dataApiRevenueStreams`
  `gql:query:dataApiRevenueStreamsCustomers` `gql:query:dataApiRevenueStreamsPlans`
  `gql:query:dataApiUsages` `gql:query:dataApiUsagesAggregatedAmounts`
  `gql:query:dataApiUsagesInvoiced` — `Services/DataApi/*` (ClickHouse-backed) present.
- Usage & events (7): `gql:query:customerUsage` `gql:query:customerProjectedUsage`
  `gql:query:event` `gql:query:events` `gql:query:eventTypes`
  `gql:query:selectableBillableMetrics` `gql:query:selectablePlans`
  — `Services/Invoices/CustomerUsageService`, `Services/LifetimeUsages`,
  `Services/Events` present; **gap:** an events ransack-style read query
  (`EventsQuery` exists — extend for the resolver filters, S/M).
  `selectable*` are filtered re-uses of the `billableMetrics`/`plans` resolvers (S).
- Alerts & wallet transactions (6): `gql:query:subscriptionAlert`
  `gql:query:subscriptionAlerts` `gql:query:walletAlert` `gql:query:walletAlerts`
  `gql:query:walletTransactionConsumptions` `gql:query:walletTransactionFundings`
  — alert CRUD rows are already done (`UsageMonitoringAlertsTest`); these are
  the fetch variants + two wallet-transaction collection filters. S.

---

## Arc 4 — Customer portal (effort M; prerequisite: portal auth guard)

15 rows — every underlying service is ported; the work is the portal
authentication context (Rails' `AuthenticableApiUser` portal variant +
`resolvers/customer_portal/*` wrappers around the same resolvers Arc 3 wires,
scoped to the portal customer) and then thin mutations.

Prerequisite: a `CustomerPortal` guard in `app/GraphQL/Guards/` (portal token
from `generateCustomerPortalUrl` / the portal session), then:

- Queries (11): `gql:query:customerPortalCustomerProjectedUsage`
  `gql:query:customerPortalCustomerUsage` `gql:query:customerPortalInvoiceCollections`
  `gql:query:customerPortalInvoices` `gql:query:customerPortalOrganization`
  `gql:query:customerPortalOverdueBalances` `gql:query:customerPortalSubscription`
  `gql:query:customerPortalSubscriptions` `gql:query:customerPortalUser`
  `gql:query:customerPortalWallet` `gql:query:customerPortalWallets`
- Mutations (4): `gql:mutation:createCustomerPortalWalletTransaction`
  `gql:mutation:updateCustomerPortalCustomer` `gql:mutation:downloadCustomerPortalInvoice`
  `gql:mutation:generateCustomerPortalUrl`

Sequence the queries after the guard, `generateCustomerPortalUrl` first among
mutations. Depends on Arc 3b (overdue balances / usage / invoice collections
resolvers) for maximum reuse.

---

## Backlog — blocked on unported domains (53 rows, post-tail)

Not launchable until their domain lands. Ordered by likely value:

| Domain | Rows | Rails sources | Prerequisite |
|---|---|---|---|
| Integration mappings (7) | `gql:mutation:createIntegrationCollectionMapping` `gql:mutation:updateIntegrationCollectionMapping` `gql:mutation:destroyIntegrationCollectionMapping` `gql:mutation:createIntegrationMapping` `gql:mutation:updateIntegrationMapping` `gql:mutation:destroyIntegrationMapping` `gql:query:integrationCollectionMappings` | `mutations/{integration_mappings,integration_collection_mappings}`, `services/integration_mappings` | Models + services (`IntegrationMapping`, `IntegrationCollectionMapping` — neither model exists yet). M. Natural follow-on to the just-landed collectors slice. |
| Catalog plans (5) | `gql:mutation:createCatalogPlan` `gql:mutation:updateCatalogPlan` `gql:mutation:destroyCatalogPlan` `gql:query:catalogPlan` `gql:query:catalogPlans` | `mutations/catalog_plans`, `services/catalog_plans` | Model `CatalogPlan` exists; port the 3 services. M. |
| Admin surface (7) | `gql:mutation:adminCreateOrganization` `gql:mutation:adminRollbackChange` `gql:mutation:adminToggleFeature` `gql:query:adminAuditLogs` `gql:query:adminCsAdmins` `gql:query:adminOrganization` `gql:query:adminOrganizations` | `mutations/admin`, `resolvers/admin` | cs-admin auth context + `services/admin`. M. |
| Logs (6) | `gql:query:activityLog` `gql:query:activityLogs` `gql:query:apiLog` `gql:query:apiLogs` `gql:query:securityLog` `gql:query:securityLogs` | `resolvers/{activity_log,activity_logs,api_log,api_logs,security_log,security_logs}` | Models `ApiLog`, `SecurityLog`, `ActivityLog` unported (`versions` table + `Versionable` exist — activity logs can build on it). M/L. |
| Usage attribution (5) | `gql:mutation:createUsageAttributionType` `gql:mutation:updateUsageAttributionType` `gql:mutation:destroyUsageAttributionType` `gql:query:usageAttributionType` `gql:query:usageAttributionTypes` | `mutations/usage_attribution_types`, `services/usage_attribution_types` | Models + services unported. M. |
| Adjusted fees (3) | `gql:mutation:createAdjustedFee` `gql:mutation:destroyAdjustedFee` `gql:mutation:previewAdjustedFee` | `mutations/adjusted_fees`, `services/adjusted_fees` | Model `AdjustedFee` exists; port create/destroy/estimate services. M. |
| Invoice custom sections (5) | `gql:mutation:createInvoiceCustomSection` `gql:mutation:updateInvoiceCustomSection` `gql:mutation:destroyInvoiceCustomSection` `gql:query:invoiceCustomSection` `gql:query:invoiceCustomSections` | `mutations/invoice_custom_sections`, `services/invoice_custom_sections` | Model + services unported. S/M. |
| Invoice regeneration (2) | `gql:mutation:regenerateFromVoided` `gql:query:invoiceBuildRegenerationPreview` | `mutations/invoices/regenerate_from_voided`, `resolvers/invoice_build_regeneration_preview` | Regeneration service unported (heavy — re-uses fee engine). M/L. |
| Tax provider retries (2) | `gql:mutation:retryTaxProviderVoiding` `gql:mutation:retryTaxReporting` | `mutations/invoices/retry_tax_provider_voiding`, `mutations/credit_notes/retry_tax_reporting` | Anrok/Avalara void+report clients (Laravel `Services/Integrations/{Anrok,Avalara}` only have Create/Update/FetchCompanyId). S/M. |
| Data exports (2) | `gql:mutation:createInvoicesDataExport` `gql:mutation:createCreditNotesDataExport` | `mutations/data_exports`, `services/data_exports` | Export job + `data_exports` model. M. |
| Superset (2) | `gql:query:supersetDashboards` `gql:mutation:createSupersetGuestToken` | `mutations/superset`, `resolvers/superset` | Superset API client. S. |
| Quote image (1) | `gql:mutation:addQuoteImage` | `mutations/quotes/add_image`, `services/quotes/add_image_service` | ActiveStorage-equivalent blob upload (`active_storage_*` tables are in the frozen schema but untouched so far). S/M. |
| AI agent (5 of 6) | `gql:mutation:createAiConversation` `gql:mutation:askFinanceAssistant` `gql:mutation:exportFinanceAssistantResult` `gql:query:aiConversation` `gql:query:aiConversations` | `mutations/ai_conversations`, `mutations/finance_assistant`, `services/{ai_conversations,finance_assistant}` | Full AI domain (LLM calls, conversations). XL — schedule last or de-scope. |

### N/A

- `gql:subscription:aiConversationStreamed` — the served schema intentionally
  drops the GraphQL subscription root (`GraphqlSubscription`, ActionCable-only;
  whitelisted in `graphql/FULL_SCHEMA_NOTES.md` and asserted by
  `IntrospectionDiffTest`). No resolver can ever satisfy this row against the
  served surface. **Recommend marking N/A** (revisit only if an
  ActionCable-compat subscription transport is ever scoped; the AI domain
  blocker applies regardless).

---

## Launch order

1. **Slice 0** (S) — flip 54 stale rows, close 21 test gaps. Shrinks the tail
   by ~23% with no feature work; do it before any arc so velocity numbers are real.
2. **Arc 1** (69 rows) — biggest ready cluster, zero service risk, fully
   parallelizable per sub-table (taxes/products/rate-cards/contracts/charges).
3. **Arc 2** (45 rows) — payments + invoices; only 2 small service gaps.
4. **Arc 3** (71 rows) — 3a and 3b can run as two independent agents; 3a has
   four small service ports (invites/memberships/roles/password-resets).
5. **Arc 4** (15 rows) — after Arc 3b lands (reuses its resolvers behind the
   portal guard).
6. **Backlog** — integration mappings and catalog plans first (closest to
   landed code), AI agent last or de-scoped.
