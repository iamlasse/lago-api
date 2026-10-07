<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\PlansController;
use App\Http\Controllers\Api\V1\TaxesController;
use App\Http\Controllers\Api\V1\AddOnsController;
use App\Http\Controllers\Api\V1\EventsController;
use App\Http\Controllers\Api\V1\OrdersController;
use App\Http\Controllers\Api\V1\QuotesController;
use App\Http\Controllers\Api\V1\CouponsController;
use App\Http\Controllers\Api\V1\WalletsController;
use App\Http\Controllers\Api\V1\FeaturesController;
use App\Http\Controllers\Api\V1\InvoicesController;
use App\Http\Controllers\Api\V1\PaymentsController;
use App\Http\Controllers\Api\V1\ProductsController;
use App\Http\Controllers\Api\V1\ContractsController;
use App\Http\Controllers\Api\V1\CustomersController;
use App\Http\Controllers\Api\V1\RateCardsController;
use App\Http\Controllers\Api\V1\OrderFormsController;
use App\Http\Controllers\Api\V1\CreditNotesController;
use App\Http\Controllers\Api\V1\OrganizationsController;
use App\Http\Controllers\Api\V1\Plans\ChargesController;
use App\Http\Controllers\Api\V1\QuoteVersionsController;
use App\Http\Controllers\Api\V1\SubscriptionsController;
use App\Http\Controllers\Api\V1\Analytics\MrrsController;
use App\Http\Controllers\Api\V1\AppliedCouponsController;
use App\Http\Controllers\Api\V1\DataApi\UsagesController;
use App\Http\Controllers\Api\V1\BillableMetricsController;
use App\Http\Controllers\Api\V1\PaymentReceiptsController;
use App\Http\Controllers\Api\V1\PaymentRequestsController;
use App\Http\Controllers\Api\V1\Quotes\VersionsController;
use App\Http\Controllers\Api\V1\WebhookEndpointsController;
use App\Http\Controllers\Api\V1\ProductCategoriesController;
use App\Http\Controllers\Api\V1\Plans\FixedChargesController;
use App\Http\Controllers\Api\V1\WalletTransactionsController;
use App\Http\Controllers\Api\V1\Plans\Charges\FiltersController;
use App\Http\Controllers\Api\V1\Plans\AppliedRateCardsController;
use App\Http\Controllers\Api\V1\Analytics\GrossRevenuesController;
use App\Http\Controllers\Api\V1\Analytics\InvoicedUsagesController;
use App\Http\Controllers\Api\V1\Analytics\OverdueBalancesController;
use App\Http\Controllers\Api\V1\Analytics\InvoiceCollectionsController;
use App\Http\Controllers\Api\V1\Customers\UsageController as CustomerUsageController;
use App\Http\Controllers\Api\V1\RateCards\RatesController as RateCardRatesController;
use App\Http\Controllers\Api\V1\Products\FiltersController as ProductFiltersController;
use App\Http\Controllers\Api\V1\Customers\WalletsController as CustomerWalletsController;
use App\Http\Controllers\Api\V1\Customers\PaymentsController as CustomerPaymentsController;
use App\Http\Controllers\Api\V1\Plans\EntitlementsController as PlanEntitlementsController;
use App\Http\Controllers\Api\V1\Features\PrivilegesController as FeaturePrivilegesController;
use App\Http\Controllers\Api\V1\Subscriptions\AlertsController as SubscriptionAlertsController;
use App\Http\Controllers\Api\V1\Customers\CreditNotesController as CustomerCreditNotesController;
use App\Http\Controllers\Api\V1\Customers\Wallets\AlertsController as CustomerWalletAlertsController;
use App\Http\Controllers\Api\V1\Customers\AppliedCouponsController as CustomerAppliedCouponsController;
use App\Http\Controllers\Api\V1\Customers\PaymentMethodsController as CustomerPaymentMethodsController;
use App\Http\Controllers\Api\V1\Customers\ProjectedUsageController as CustomerProjectedUsageController;
use App\Http\Controllers\Api\V1\Customers\PaymentRequestsController as CustomerPaymentRequestsController;
use App\Http\Controllers\Api\V1\Contracts\AppliedRateCardsController as AppliedContractRateCardsController;
use App\Http\Controllers\Api\V1\Subscriptions\EntitlementsController as SubscriptionEntitlementsController;
use App\Http\Controllers\Api\V1\Plans\Entitlements\PrivilegesController as PlanEntitlementPrivilegesController;
use App\Http\Controllers\Api\V1\Subscriptions\LifetimeUsagesController as SubscriptionLifetimeUsagesController;
use App\Http\Controllers\Api\V1\Plans\AppliedRateCards\RatePhasesController as PlanRateCardRatePhasesController;
use App\Http\Controllers\Api\V1\Contracts\AppliedRateCards\RatePhasesController as ContractRateCardRatePhasesController;
use App\Http\Controllers\Api\V1\Subscriptions\Entitlements\PrivilegesController as SubscriptionEntitlementPrivilegesController;

/*
|--------------------------------------------------------------------------
| REST API v1 (mirrored at v2)
|--------------------------------------------------------------------------
|
| The Rails route table is drawn from a live `rails routes` dump into
| tests/inventory/rest.json; routes are added here per-port with their
| tests. The $sharedApi block below is the entire v1 table and is mounted
| verbatim under both /api/v1 and /api/v2 — the v2 mount carries the
| `X-Lago-Endpoint-Status: beta` header on every response (Rails keys it
| on the request path inside Api::BaseController, including error
| responses).
|
| Not registered yet (dependencies out of scope):
| - customers usage endpoints (past_usage, checkout_url, portal_url) —
|   current_usage / projected_usage are registered (the events store +
|   aggregation services landed); past_usage waits on PastUsageQuery;
| - the remaining customers nested subresources (invoices, subscriptions,
|   payments, payment_requests, payment_methods, and the wallets
|   alerts/metadata subresources).
*/

$sharedApi = function (): void {
    Route::middleware('lago.auth')->group(function (): void {
        // GET /api/v1/organizations shows the CURRENT organization (Rails:
        // organizations#show; there is no index/create).
        Route::get('organizations', [OrganizationsController::class, 'show']);
        Route::put('organizations', [OrganizationsController::class, 'update']);
        Route::get('organizations/grpc_token', [OrganizationsController::class, 'grpcToken']);

        Route::get('customers', [CustomersController::class, 'index']);
        Route::post('customers', [CustomersController::class, 'create']);

        // -- billable metrics ---------------------------------------------------
        // Codes may contain dots, so the member routes carry the `.+`
        // constraint (mirrors Rails' `param: :code, code: /.*/`); the
        // evaluate_expression collection route is registered ahead of them.
        //
        // TODO(port): the Lago expression parser — evaluate_expression
        // validates the blank-expression case and answers Rails'
        // invalid_expression envelope for every non-blank expression instead
        // of evaluating it (see BillableMetricsController).
        Route::prefix('billable_metrics')->as('billable_metrics:')->group(function (): void {
            Route::get('', [BillableMetricsController::class, 'index']);
            Route::post('', [BillableMetricsController::class, 'create']);
            Route::post('evaluate_expression', [BillableMetricsController::class, 'evaluateExpression']);

            Route::get('{code}', [BillableMetricsController::class, 'show'])
                ->where('code', '.+');
            Route::put('{code}', [BillableMetricsController::class, 'update'])
                ->where('code', '.+');
            Route::patch('{code}', [BillableMetricsController::class, 'update'])
                ->where('code', '.+');
            Route::delete('{code}', [BillableMetricsController::class, 'destroy'])
                ->where('code', '.+');
        });

        // -- features ---------------------------------------------------------
        // Keyed by code, which may contain dots (Rails: `resources :features,
        // param: :code` with the wildcard code constraint); the nested
        // privileges destroy is scoped to the parent feature's code.
        Route::prefix('features')->as('features:')->group(function (): void {
            Route::get('', [FeaturesController::class, 'index']);
            Route::post('', [FeaturesController::class, 'create']);

            Route::prefix('{feature_code}')
                ->group(function (): void {
                    Route::get('', [FeaturesController::class, 'show']);
                    Route::put('', [FeaturesController::class, 'update']);
                    Route::patch('', [FeaturesController::class, 'update']);
                    Route::delete('', [FeaturesController::class, 'destroy']);

                    Route::delete('privileges/{code}', [FeaturePrivilegesController::class, 'destroy'])
                        ->where('code', '.+');
                });
        });

        // -- taxes -----------------------------------------------------------------
        // Keyed by code like billable metrics (Rails: `resources :taxes,
        // param: :code, code: /.*/`).
        Route::prefix('taxes')->as('taxes:')->group(function (): void {
            Route::get('', [TaxesController::class, 'index']);
            Route::post('', [TaxesController::class, 'create']);

            Route::get('{code}', [TaxesController::class, 'show'])
                ->where('code', '.+');
            Route::put('{code}', [TaxesController::class, 'update'])
                ->where('code', '.+');
            Route::patch('{code}', [TaxesController::class, 'update'])
                ->where('code', '.+');
            Route::delete('{code}', [TaxesController::class, 'destroy'])
                ->where('code', '.+');
        });

        // -- webhook endpoints ------------------------------------------------------
        // Keyed by uuid id — Rails draws these with the default param
        // constraint (no dots), so no `.+` here.
        Route::prefix('webhook_endpoints')->as('webhook_endpoints:')->group(function (): void {
            Route::get('', [WebhookEndpointsController::class, 'index']);
            Route::post('', [WebhookEndpointsController::class, 'create']);

            Route::get('{id}', [WebhookEndpointsController::class, 'show']);
            Route::put('{id}', [WebhookEndpointsController::class, 'update']);
            Route::patch('{id}', [WebhookEndpointsController::class, 'update']);
            Route::delete('{id}', [WebhookEndpointsController::class, 'destroy']);
        });

        // -- plans ------------------------------------------------------------
        // Nested plan subresources are registered BEFORE the member :code
        // routes below (registration order is match priority, like Rails'
        // draw order inside resources :plans). Plan and charge codes may
        // contain dots, so the member routes carry the `.+` constraint
        // (mirrors Rails' `param: :code, code: /.*/`).
        //
        // Entitlements are registered below (EntitlementsController); the
        // metadata subresource and charge filter create/update/destroy are
        // not registered yet (dependencies do not exist — no ported
        // services; only index/show need no service).
        // create/update/destroy (ChargeFilters::Create/Update/DestroyService
        // not ported — only index/show need no service).
        Route::prefix('plans')->as('plans:')->group(function (): void {
            Route::get('', [PlansController::class, 'index']);
            Route::post('', [PlansController::class, 'create']);
            Route::get('{plan_code}/charges', [ChargesController::class, 'index']);
            Route::post('{plan_code}/charges', [ChargesController::class, 'create']);
            Route::get('{plan_code}/charges/{code}', [ChargesController::class, 'show']);
            Route::put('{plan_code}/charges/{code}', [ChargesController::class, 'update']);
            Route::patch('{plan_code}/charges/{code}', [ChargesController::class, 'update']);
            Route::delete('{plan_code}/charges/{code}', [ChargesController::class, 'destroy']);

            Route::get('{plan_code}/charges/{charge_code}/filters', [FiltersController::class, 'index']);
            Route::get('{plan_code}/charges/{charge_code}/filters/{id}', [FiltersController::class, 'show']);

            Route::get('{plan_code}/fixed_charges', [FixedChargesController::class, 'index']);
            Route::post('{plan_code}/fixed_charges', [FixedChargesController::class, 'create']);
            Route::get('{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'show']);
            Route::put('{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'update']);
            Route::patch('{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'update']);
            Route::delete('{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'destroy']);

            // Nested entitlements (Rails: resources :entitlements,
            // param: :code under the plans draw — plan codes AND feature
            // codes may contain dots, so both segments carry the wildcard
            // constraint). POST is a full sync, PATCH a partial merge; the
            // nested privileges destroy removes one privilege value.
            Route::get('{plan_code}/entitlements', [PlanEntitlementsController::class, 'index']);
            Route::post('{plan_code}/entitlements', [PlanEntitlementsController::class, 'create']);
            Route::patch('{plan_code}/entitlements', [PlanEntitlementsController::class, 'update']);

            Route::delete(
                '{plan_code}/entitlements/{entitlement_code}/privileges/{code}',
                [PlanEntitlementPrivilegesController::class, 'destroy'],
            )->where(['plan_code' => '.+', 'entitlement_code' => '.+', 'code' => '.+']);

            Route::get('{plan_code}/entitlements/{entitlement_code}', [PlanEntitlementsController::class, 'show'])
                ->where(['plan_code' => '.+', 'entitlement_code' => '.+']);
            Route::delete('{plan_code}/entitlements/{entitlement_code}', [PlanEntitlementsController::class, 'destroy'])
                ->where(['plan_code' => '.+', 'entitlement_code' => '.+']);

            Route::get('{code}', [PlansController::class, 'show'])->where('code', '.+');
            Route::put('{code}', [PlansController::class, 'update'])->where('code', '.+');
            Route::patch('{code}', [PlansController::class, 'update'])->where('code', '.+');
            Route::delete('{code}', [PlansController::class, 'destroy'])->where('code', '.+');
        });

        // -- subscriptions ----------------------------------------------------
        // DELETE on a subscription never destroys the row: it terminates it
        // (Subscriptions\TerminateService). The nested entitlements
        // subresource is keyed by the subscription external_id (which may
        // contain dots) and the feature code: PATCH merges the entitlements
        // hash, DELETE removes one feature entitlement (Rails draws no
        // create — overrides are merged in).
        Route::prefix('subscriptions')->as('subscriptions:')->group(function (): void {
            Route::get('', [SubscriptionsController::class, 'index']);
            Route::post('', [SubscriptionsController::class, 'create']);

            Route::prefix('{external_id}')->group(function (): void {
                Route::get('entitlements', [SubscriptionEntitlementsController::class, 'index']);
                Route::patch('entitlements', [SubscriptionEntitlementsController::class, 'update']);

                // NOTE: the nested privileges route is registered BEFORE
                // the plain {code} delete — with the wildcard code
                // constraint, registration order is match priority (Rails'
                // nested draw wins recognition the same way).
                Route::delete(
                    'entitlements/{entitlement_code}/privileges/{code}',
                    [SubscriptionEntitlementPrivilegesController::class, 'destroy'],
                )->where(['entitlement_code' => '.+', 'code' => '.+']);
                Route::delete('entitlements/{code}', [SubscriptionEntitlementsController::class, 'destroy'])
                    ->where('code', '.+');

                Route::get('', [SubscriptionsController::class, 'show']);
                Route::put('', [SubscriptionsController::class, 'update']);
                Route::patch('', [SubscriptionsController::class, 'update']);
                Route::delete('', [SubscriptionsController::class, 'terminate']);
            });
        });

        // -- invoices ---------------------------------------------------------
        // Keyed by uuid id (Rails: resources :invoices — the default param
        // constraint applies; uuids contain no dots). Member actions per
        // rest.json: finalize/refresh are PUT (Rails' non-RESTful draw),
        // void/retry/lose_dispute/download_* are POST.
        //
        // Not registered yet (dependencies do not exist): retry_payment
        // (Invoices::Payments::RetryService) and sync_salesforce_id
        // (SyncSalesforceIdService) — each has its ledger row and lands with
        // its milestone. Preview IS registered but premium-gated (Rails:
        // PremiumFeatureOnly → 403 feature_unavailable in the OSS image);
        // resend_email is premium-gated inside Emails::ResendService and
        // payment_url's happy path lives with the PSP slice
        // (PaymentIntents::FetchService).
        Route::prefix('invoices')->as('invoices:')->group(function (): void {
            Route::get('', [InvoicesController::class, 'index']);
            Route::post('', [InvoicesController::class, 'create']);
            Route::post('preview', [InvoicesController::class, 'preview']);

            Route::put('{id}/finalize', [InvoicesController::class, 'finalize']);
            Route::put('{id}/refresh', [InvoicesController::class, 'refresh']);

            Route::post('{id}/void', [InvoicesController::class, 'void']);
            Route::post('{id}/retry', [InvoicesController::class, 'retry']);
            Route::post('{id}/lose_dispute', [InvoicesController::class, 'loseDispute']);
            Route::post('{id}/resend_email', [InvoicesController::class, 'resendEmail']);
            Route::post('{id}/payment_url', [InvoicesController::class, 'paymentUrl']);
            Route::post('{id}/download', [InvoicesController::class, 'downloadPdf']);
            Route::post('{id}/download_pdf', [InvoicesController::class, 'downloadPdf']);
            Route::post('{id}/download_xml', [InvoicesController::class, 'downloadXml']);

            Route::get('{id}', [InvoicesController::class, 'show']);
            Route::put('{id}', [InvoicesController::class, 'update']);
            Route::patch('{id}', [InvoicesController::class, 'update']);
            Route::delete('{id}', [InvoicesController::class, 'destroy']);
        });

        // -- events -----------------------------------------------------------
        // Keyed by transaction_id on the member route (Rails: resources
        // :events; the default [^/]+ constraint applies — percent-encoded
        // special characters survive). GET /events_enriched is a TOP-LEVEL
        // path, not nested under events/ (Rails draws it separately); it is
        // clickhouse-only (ensure_organization_uses_clickhouse -> 403
        // endpoint_not_available for every postgres org).
        //
        // Not registered yet (dependencies do not exist): estimate_fees,
        // estimate_instant_fees and batch_estimate_instant_fees
        // (Fees::EstimateInstant::PayInAdvanceService family — the
        // pay-in-advance metering slice).
        Route::prefix('events')->as('events:')->group(function (): void {
            Route::get('', [EventsController::class, 'index']);
            Route::post('', [EventsController::class, 'create']);
            Route::post('batch', [EventsController::class, 'batch']);

            Route::get('{id}', [EventsController::class, 'show'])
                // Rails constrains :id with the default [^/]+ on the RAW path
                // (percent-encoded separators are part of the segment);
                // Laravel decodes the path before matching, so the constraint
                // is widened to keep encoded transaction_ids routable.
                ->where('id', '.+');
        });

        Route::get('events_enriched', [EventsController::class, 'indexEnriched']);

        // -- coupons / applied coupons ----------------------------------------
        // Registered here per the rest.json rows; the CONTROLLERS land in the
        // coupons slice (same class names) — the routes resolve as soon as
        // those classes exist. Coupons are keyed by code, which may contain
        // dots (Rails: `resources :coupons, param: :code, code: /.*/`).
        Route::prefix('coupons')->as('coupons:')->group(function (): void {
            Route::get('', [CouponsController::class, 'index']);
            Route::post('', [CouponsController::class, 'create']);

            Route::get('{code}', [CouponsController::class, 'show'])->where('code', '.+');
            Route::put('{code}', [CouponsController::class, 'update'])->where('code', '.+');
            Route::patch('{code}', [CouponsController::class, 'update'])->where('code', '.+');
            Route::delete('{code}', [CouponsController::class, 'destroy'])->where('code', '.+');
        });

        Route::get('applied_coupons', [AppliedCouponsController::class, 'index']);
        Route::post('applied_coupons', [AppliedCouponsController::class, 'create']);

        // -- wallets ------------------------------------------------------------
        // Keyed by uuid id on the top-level resource; DELETE never destroys —
        // it terminates (Wallets\TerminateService). The nested
        // wallet_transactions index belongs to the top-level
        // WalletTransactionsController (Rails: a standalone draw
        // `get "/wallets/:id/wallet_transactions"`).
        //
        // Not registered yet (dependencies do not exist): the wallets/:id/
        // metadata subresource (Metadata::ItemMetadata controller slice).
        Route::prefix('wallets')->as('wallets:')->group(function (): void {
            Route::get('', [WalletsController::class, 'index']);
            Route::post('', [WalletsController::class, 'create']);
            Route::get('{id}/wallet_transactions', [WalletTransactionsController::class, 'index']);

            Route::get('{id}', [WalletsController::class, 'show']);
            Route::put('{id}', [WalletsController::class, 'update']);
            Route::patch('{id}', [WalletsController::class, 'update']);
            Route::delete('{id}', [WalletsController::class, 'terminate']);
        });

        // POST /wallet_transactions creates paid/granted/voided transactions
        // in one call; GET /wallet_transactions/:id reads one back.
        Route::post('wallet_transactions', [WalletTransactionsController::class, 'create']);
        Route::get('wallet_transactions/{id}', [WalletTransactionsController::class, 'show']);

        // Not registered yet (dependencies do not exist): payment_url
        // (GeneratePaymentUrlService), consumptions / fundings
        // (WalletTransactionConsumptionsQuery + serializers).

        // -- credit notes -------------------------------------------------------
        // Keyed by uuid id (Rails: resources :credit_notes — the default
        // param constraint applies; uuids contain no dots). Member actions
        // per rest.json: void is PUT (Rails' non-RESTful draw),
        // download/download_pdf/download_xml are POST; estimate is a
        // collection POST drawn before the member routes.
        //
        // Not registered yet (dependencies do not exist): resend_email
        // (Emails::ResendService) and the :id/metadata subresource
        // (Metadata::ItemMetadata is not attached to credit notes yet —
        // CreditNotes::Create/UpdateService carry TODO(port)s).
        Route::prefix('credit_notes')->as('credit_notes:')->group(function (): void {
            Route::get('', [CreditNotesController::class, 'index']);
            Route::post('', [CreditNotesController::class, 'create']);
            Route::post('estimate', [CreditNotesController::class, 'estimate']);

            Route::put('{id}/void', [CreditNotesController::class, 'void']);
            Route::post('{id}/download', [CreditNotesController::class, 'downloadPdf']);
            Route::post('{id}/download_pdf', [CreditNotesController::class, 'downloadPdf']);
            Route::post('{id}/download_xml', [CreditNotesController::class, 'downloadXml']);

            Route::get('{id}', [CreditNotesController::class, 'show']);
            Route::put('{id}', [CreditNotesController::class, 'update']);
            Route::patch('{id}', [CreditNotesController::class, 'update']);
        });

        // -- customers nested subresources --------------------------------------
        // The external_id segment carries the `.+` constraint (see the
        // customers show/destroy routes below); wallets are keyed by CODE,
        // which is only unique among active wallets.
        //
        // Not registered yet (dependencies do not exist): the invoices,
        // subscriptions, payments, payment_requests, payment_methods
        // subresources, the wallets alerts/metadata subresources, and the
        // applied_coupons destroy route's controller actions beyond
        // index/destroy themselves (coupons slice).
        Route::prefix('customers/{external_id}')
            ->where(['external_id' => '.+'])
            ->as('customers:')->group(function (): void {
                Route::prefix('wallets')->as('wallets:')->group(function (): void {
                    Route::get('', [CustomerWalletsController::class, 'index']);
                    Route::post('', [CustomerWalletsController::class, 'create']);

                    Route::get('{code}', [CustomerWalletsController::class, 'show']);
                    Route::put('{code}', [CustomerWalletsController::class, 'update']);
                    Route::patch('{code}', [CustomerWalletsController::class, 'update']);
                    Route::delete('{code}', [CustomerWalletsController::class, 'terminate']);
                });

                Route::get('applied_coupons', [CustomerAppliedCouponsController::class, 'index']);
                Route::delete('applied_coupons/{id}', [CustomerAppliedCouponsController::class, 'destroy']);

                Route::get('credit_notes', [CustomerCreditNotesController::class, 'index']);

                // -- customers usage (events store consumers) ----------------------
                // Rails: get :current_usage / :projected_usage under the
                // customers nested draw (customers/usage#current and
                // customers/projected_usage#current). past_usage stays
                // unregistered (PastUsageQuery — later slice).
                Route::get('current_usage', [CustomerUsageController::class, 'current']);
                Route::get('projected_usage', [CustomerProjectedUsageController::class, 'current']);
            });

        // -- payments / payment_requests / payment_methods -----------------------
        // (Rails: shared_api `resources :payments, only: %i[create index show]`,
        // `resources :payment_requests, only: %i[create index show]`, and the
        // customers-nested payments / payment_requests / payment_methods
        // subresources.) Registered as a distinct appended block; payment
        // receipts / invoices payment_url / retry_payment / wallet
        // transactions payment_url live with their own slices.
        Route::prefix('payments')->as('payments:')->group(function (): void {
            Route::post('', [PaymentsController::class, 'create']);
            Route::get('', [PaymentsController::class, 'index']);
            Route::get('{id}', [PaymentsController::class, 'show']);
        });

        Route::prefix('payment_requests')->as('payment_requests:')->group(function (): void {
            Route::post('', [PaymentRequestsController::class, 'create']);
            Route::get('', [PaymentRequestsController::class, 'index']);
            Route::get('{id}', [PaymentRequestsController::class, 'show']);
        });

        Route::prefix('customers/{external_id}')
            ->where(['external_id' => '.+'])
            ->as('customers.payments:')->group(function (): void {
                Route::get('payments', [CustomerPaymentsController::class, 'index']);
                Route::get('payment_requests', [CustomerPaymentRequestsController::class, 'index']);

                Route::prefix('payment_methods')->as('payment_methods:')->group(function (): void {
                    Route::get('', [CustomerPaymentMethodsController::class, 'index']);
                    Route::delete('{id}', [CustomerPaymentMethodsController::class, 'destroy']);
                    Route::put('{id}/set_as_default', [CustomerPaymentMethodsController::class, 'setAsDefault']);
                });
            });
        // NOTE (usage-monitoring slice): the wallet-alerts nested routes MUST
        // be registered BEFORE the customers show/destroy routes below —
        // those carry the greedy `.+` external_id constraint and would
        // otherwise swallow `customers/x/wallets/code/alerts` (Laravel matches
        // by registration order).
        Route::prefix('customers/{external_id}')
            ->where(['external_id' => '.+'])
            ->as('customers:')->group(function (): void {
                Route::prefix('wallets/{code}')->as('wallets:')->group(function (): void {
                    Route::prefix('alerts')->as('alerts:')->group(function (): void {
                        Route::delete('/', [CustomerWalletAlertsController::class, 'destroyAll']);
                        Route::get('/', [CustomerWalletAlertsController::class, 'index']);
                        Route::post('/', [CustomerWalletAlertsController::class, 'create']);

                        Route::get('{alert_code}', [CustomerWalletAlertsController::class, 'show'])
                            ->where('alert_code', '.+');
                        Route::put('{alert_code}', [CustomerWalletAlertsController::class, 'update'])
                            ->where('alert_code', '.+');
                        Route::patch('{alert_code}', [CustomerWalletAlertsController::class, 'update'])
                            ->where('alert_code', '.+');
                        Route::delete('{alert_code}', [CustomerWalletAlertsController::class, 'destroy'])
                            ->where('alert_code', '.+');
                    });
                });
            });

        // customers and subscriptions are looked up by external_id, which
        // may contain dots. Rails constrains those params with /[^\/]+/ (a
        // bare :external_id would truncate the value at the format
        // separator); the Laravel equivalent is the `.+` constraint below.
        Route::get('customers/{external_id}', [CustomersController::class, 'show'])
            ->where('external_id', '.+');
        Route::delete('customers/{external_id}', [CustomersController::class, 'destroy'])
            ->where('external_id', '.+');

        // == add-ons / orders / payment receipts — appended orders slice block ==
        //
        // From the rest.json rows (filter "/add_ons", "/orders",
        // "/payment_receipts"); registered as a distinct appended block.
        //
        // - Add-ons are keyed by code, which may contain dots (Rails:
        //   resources :add_ons, param: :code with the wildcard constraint).
        // - Orders are keyed by uuid id; there is NO create route (orders
        //   are created when an order form is signed) — index, show and
        //   execute only.
        // - The payment_receipts rows are registered here for the parallel
        //   receipts slice under the PINNED class names
        //   App\Http\Controllers\Api\V1\PaymentReceiptsController (the
        //   routes resolve as soon as that class lands). Verified against
        //   rest.json: there are NO customer-nested payment_receipts rows.
        Route::prefix('add_ons')->as('add_ons:')->group(function (): void {
            Route::get('', [AddOnsController::class, 'index']);
            Route::post('', [AddOnsController::class, 'create']);

            Route::get('{code}', [AddOnsController::class, 'show'])->where('code', '.+');
            Route::put('{code}', [AddOnsController::class, 'update'])->where('code', '.+');
            Route::patch('{code}', [AddOnsController::class, 'update'])->where('code', '.+');
            Route::delete('{code}', [AddOnsController::class, 'destroy'])->where('code', '.+');
        });

        Route::prefix('orders')->as('orders:')->group(function (): void {
            Route::get('', [OrdersController::class, 'index']);

            Route::post('{id}/execute', [OrdersController::class, 'execute']);

            Route::get('{id}', [OrdersController::class, 'show']);
        });

        Route::prefix('payment_receipts')->as('payment_receipts:')->group(function (): void {
            Route::get('', [PaymentReceiptsController::class, 'index']);
            Route::post('{id}/resend_email', [PaymentReceiptsController::class, 'resendEmail']);
            Route::get('{id}', [PaymentReceiptsController::class, 'show']);
        });

        // == quotes / quote_versions / order_forms — appended order-forms slice block ==
        //
        // From the rest.json rows (filter "/quotes", "/quote_versions",
        // "/order_forms"): quotes are READ-ONLY over REST — creation, updates
        // and the version lifecycle transitions live on the GraphQL surface
        // (Rails keeps createQuote/updateQuote/addQuoteImage and
        // updateQuoteVersion off the REST router). REST owns the reads plus
        // the approve / void / clone and mark_as_signed / void transitions.
        // The rows exist for both /api/v1 and /api/v2; both prefixes mount the
        // same $sharedApi closure, so one block serves both.
        Route::prefix('quotes')->as('quotes:')->group(function (): void {
            Route::get('', [QuotesController::class, 'index']);
            Route::get('{id}', [QuotesController::class, 'show']);
            Route::get('{quote_id}/versions', [VersionsController::class, 'index']);
        });

        Route::prefix('quote_versions')->as('quote_versions:')->group(function (): void {
            Route::get('{id}', [QuoteVersionsController::class, 'show']);
            Route::post('{id}/approve', [QuoteVersionsController::class, 'approve']);
            Route::post('{id}/void', [QuoteVersionsController::class, 'void']);
            Route::post('{id}/clone', [QuoteVersionsController::class, 'clone']);
        });

        Route::prefix('order_forms')->as('order_forms:')->group(function (): void {
            Route::get('', [OrderFormsController::class, 'index']);
            Route::post('{id}/mark_as_signed', [OrderFormsController::class, 'markAsSigned']);
            Route::post('{id}/void', [OrderFormsController::class, 'void']);
            Route::get('{id}', [OrderFormsController::class, 'show']);
        });

        // == analytics (Lago Data API proxy) — appended analytics-slice block ====
        //
        // The ONLY /analytics* route Rails serves from the Data API HTTP proxy:
        //
        //   get "analytics/usage", to: "data_api/usages#index"   (config/routes/shared_api.rb)
        //
        // Rails proxies it to LAGO_DATA_API_URL with the
        // LAGO_DATA_API_BEARER_TOKEN bearer (DataApi::UsagesService) and
        // renders the Data API JSON under the top-level "usages" key. There is
        // no premium gate on the route itself (the service filters params by
        // license instead); the api-permissions resource is "analytic".
        Route::get('analytics/usage', [UsagesController::class, 'index']);

        // == analytics (the five raw-SQL analytics endpoints) ===================
        //
        // From config/routes/shared_api.rb:
        //
        //   namespace :analytics do
        //     get :gross_revenue,    to: "gross_revenues#index"
        //     get :invoiced_usage,   to: "invoiced_usages#index"
        //     get :invoice_collection, to: "invoice_collections#index"
        //     get :mrr,              to: "mrrs#index"
        //     get :overdue_balance,  to: "overdue_balances#index"
        //   end
        //
        // These are served by Api::V1::Analytics::*Controller over the
        // Analytics::* raw-SQL models (App\Models\Analytics — note Rails
        // runs those on the PRIMARY connection, not ClickHouse). The
        // premium gate lives in the SERVICES, exactly like Rails:
        // invoiced_usage / invoice_collection / mrr answer
        // forbidden_failure! ("feature_unavailable") without a license;
        // gross_revenue and overdue_balance have none. The api-permissions
        // resource is "analytic" (shared with the usage proxy above).
        Route::prefix('analytics')->as('analytics:')->group(function (): void {
            Route::get('gross_revenue', [GrossRevenuesController::class, 'index']);
            Route::get('invoiced_usage', [InvoicedUsagesController::class, 'index']);
            Route::get('invoice_collection', [InvoiceCollectionsController::class, 'index']);
            Route::get('mrr', [MrrsController::class, 'index']);
            Route::get('overdue_balance', [OverdueBalancesController::class, 'index']);
        });

        // == usage monitoring (alerts / lifetime_usage) — appended
        // usage-monitoring-slice block ==========================================
        //
        // From config/routes/shared_api.rb:
        //
        //   customers nested draw (module: :customers do ... end):
        //     resources :wallets, param: :code do
        //       scope module: :wallets do
        //         resources :alerts, only: [...], param: :code do
        //           collection { delete "/", action: :destroy_all }
        //         end
        //       end
        //     end
        //
        //   resources :subscriptions, param: :external_id do
        //     resource :lifetime_usage, only: %i[show update],
        //       controller: "subscriptions/lifetime_usages"
        //     resources :alerts, only: [...], param: :code,
        //       controller: "subscriptions/alerts" do
        //       collection { delete "/", action: :destroy_all }
        //     end
        //   end
        //
        // The wallet alert code and the subscription alert code route params
        // carry the `.+` wildcard (codes may contain dots, Rails'
        // `param: :code` without a constraint would clash with the nested
        // destroy_all; the explicit collection DELETE is registered first).

        Route::prefix('subscriptions/{external_id}')->where(['external_id' => '.+'])->group(function (): void {
            Route::get('lifetime_usage', [SubscriptionLifetimeUsagesController::class, 'show']);
            Route::put('lifetime_usage', [SubscriptionLifetimeUsagesController::class, 'update']);
            Route::patch('lifetime_usage', [SubscriptionLifetimeUsagesController::class, 'update']);

            Route::prefix('alerts')->as('subscriptions:alerts:')->group(function (): void {
                Route::delete('/', [SubscriptionAlertsController::class, 'destroyAll']);
                Route::get('/', [SubscriptionAlertsController::class, 'index']);
                Route::post('/', [SubscriptionAlertsController::class, 'create']);

                Route::get('{alert_code}', [SubscriptionAlertsController::class, 'show'])
                    ->where('alert_code', '.+');
                Route::put('{alert_code}', [SubscriptionAlertsController::class, 'update'])
                    ->where('alert_code', '.+');
                Route::patch('{alert_code}', [SubscriptionAlertsController::class, 'update'])
                    ->where('alert_code', '.+');
                Route::delete('{alert_code}', [SubscriptionAlertsController::class, 'destroy'])
                    ->where('alert_code', '.+');
            });
        });
    });
};

// == v2 product catalog (drawn AHEAD of the shared mounts, like Rails —
// config/routes.rb draws the v2 catalog first so the greedy v2 :code does
// not swallow these paths; the v2 mounts below follow) =====================
//
// V2-ONLY rows from tests/inventory/rest.json (handler modules
// api/v2/products, product_categories, rate_cards, contracts and the nested
// plan_rate_cards / contract_rate_cards with their rate_phases). The
// CONTROLLERS land with the parallel catalog slice under these pinned class
// names — the routes resolve as soon as those classes exist.
//
// A contract is never destroyed — DELETE terminates it (contracts#terminate),
// the same idiom subscriptions follow.
Route::prefix('v2')
    ->middleware([SetBetaHeader::class, 'lago.auth'])
    ->group(function (): void {
        Route::prefix('products')->as('products:')->group(function (): void {
            Route::get('', [ProductsController::class, 'index']);
            Route::post('', [ProductsController::class, 'create']);

            Route::prefix('{product_code}')->group(function (): void {
                Route::get('filters', [ProductFiltersController::class, 'index']);
                Route::post('filters', [ProductFiltersController::class, 'create']);
                Route::get('filters/{code}', [ProductFiltersController::class, 'show'])
                    ->where('code', '.+');
                Route::put('filters/{code}', [ProductFiltersController::class, 'update'])
                    ->where('code', '.+');
                Route::patch('filters/{code}', [ProductFiltersController::class, 'update'])
                    ->where('code', '.+');
                Route::delete('filters/{code}', [ProductFiltersController::class, 'destroy'])
                    ->where('code', '.+');

                Route::get('', [ProductsController::class, 'show']);
                Route::put('', [ProductsController::class, 'update']);
                Route::patch('', [ProductsController::class, 'update']);
                Route::delete('', [ProductsController::class, 'destroy']);
            });
        });

        Route::prefix('product_categories')->as('product_categories:')->group(function (): void {
            Route::get('', [ProductCategoriesController::class, 'index']);
            Route::post('', [ProductCategoriesController::class, 'create']);

            Route::get('{code}', [ProductCategoriesController::class, 'show'])->where('code', '.+');
            Route::put('{code}', [ProductCategoriesController::class, 'update'])->where('code', '.+');
            Route::patch('{code}', [ProductCategoriesController::class, 'update'])->where('code', '.+');
            Route::delete('{code}', [ProductCategoriesController::class, 'destroy'])->where('code', '.+');
        });

        Route::prefix('rate_cards')->as('rate_cards:')->group(function (): void {
            Route::get('', [RateCardsController::class, 'index']);
            Route::post('', [RateCardsController::class, 'create']);

            Route::prefix('{rate_card_code}')->group(function (): void {
                Route::get('rates', [RateCardRatesController::class, 'index']);
                Route::post('rates', [RateCardRatesController::class, 'create']);
                Route::get('rates/{code}', [RateCardRatesController::class, 'show'])->where('code', '.+');
                Route::put('rates/{code}', [RateCardRatesController::class, 'update'])->where('code', '.+');
                Route::patch('rates/{code}', [RateCardRatesController::class, 'update'])->where('code', '.+');
                Route::delete('rates/{code}', [RateCardRatesController::class, 'destroy'])->where('code', '.+');

                Route::get('', [RateCardsController::class, 'show']);
                Route::put('', [RateCardsController::class, 'update']);
                Route::patch('', [RateCardsController::class, 'update']);
                Route::delete('', [RateCardsController::class, 'destroy']);
            });
        });

        // /v2/plans/:code/applied_rate_cards (+ nested rate_phases) — the
        // plan_rate_cards handler module. Registered before the shared
        // mounts' v2 plans member route so the greedy :code cannot swallow
        // the applied_rate_cards paths.
        Route::prefix('plans')->as('plans:')->group(function (): void {
            Route::get('{plan_code}/applied_rate_cards', [AppliedRateCardsController::class, 'index'])
                ->where('plan_code', '.+');
            Route::post('{plan_code}/applied_rate_cards', [AppliedRateCardsController::class, 'create'])
                ->where('plan_code', '.+');

            Route::prefix('{plan_code}/applied_rate_cards/{rate_card_code}')
                ->where(['plan_code' => '.+', 'rate_card_code' => '.+'])
                ->group(function (): void {
                    Route::get('rate_phases', [PlanRateCardRatePhasesController::class, 'index']);
                    Route::post('rate_phases', [PlanRateCardRatePhasesController::class, 'create']);
                    Route::put('rate_phases/{code}', [PlanRateCardRatePhasesController::class, 'update'])
                        ->where('code', '.+');
                    Route::patch('rate_phases/{code}', [PlanRateCardRatePhasesController::class, 'update'])
                        ->where('code', '.+');
                    Route::delete('rate_phases/{code}', [PlanRateCardRatePhasesController::class, 'destroy'])
                        ->where('code', '.+');

                    Route::get('', [AppliedRateCardsController::class, 'show']);
                    Route::put('', [AppliedRateCardsController::class, 'update']);
                    Route::patch('', [AppliedRateCardsController::class, 'update']);
                    Route::delete('', [AppliedRateCardsController::class, 'destroy']);
                });
        });

        // /v2/contracts — external ids may contain dots (Rails constraint
        // external_id: /[^\/]+/).
        Route::prefix('contracts')->as('contracts:')->group(function (): void {
            Route::get('', [ContractsController::class, 'index']);
            Route::post('', [ContractsController::class, 'create']);

            Route::prefix('{external_id}')->group(function (): void {
                Route::prefix('applied_rate_cards')->group(function (): void {
                    Route::get('', [AppliedContractRateCardsController::class, 'index']);
                    Route::post('', [AppliedContractRateCardsController::class, 'create']);

                    Route::prefix('{rate_card_code}')->group(function (): void {
                        Route::get('rate_phases', [ContractRateCardRatePhasesController::class, 'index']);
                        Route::post('rate_phases', [ContractRateCardRatePhasesController::class, 'create']);
                        Route::put('rate_phases/{code}', [ContractRateCardRatePhasesController::class, 'update'])
                            ->where('code', '.+');
                        Route::patch('rate_phases/{code}', [ContractRateCardRatePhasesController::class, 'update'])
                            ->where('code', '.+');
                        Route::delete('rate_phases/{code}', [ContractRateCardRatePhasesController::class, 'destroy'])
                            ->where('code', '.+');

                        Route::get('', [AppliedContractRateCardsController::class, 'show']);
                        Route::put('', [AppliedContractRateCardsController::class, 'update']);
                        Route::patch('', [AppliedContractRateCardsController::class, 'update']);
                        Route::delete('', [AppliedContractRateCardsController::class, 'destroy']);
                    });
                });

                Route::get('', [ContractsController::class, 'show']);
                Route::put('', [ContractsController::class, 'update']);
                Route::patch('', [ContractsController::class, 'update']);
                Route::delete('', [ContractsController::class, 'terminate']);
            });
        });
    });

Route::prefix('v1')->group($sharedApi);

Route::prefix('v2')->middleware(SetBetaHeader::class)->group($sharedApi);

// Unmatched /api/* routes render the Lago 404 envelope (Rails:
// ApplicationController#not_found -> {"status":404,"error":"Not Found",
// "code":"resource_not_found"}).
Route::fallback(fn (): never => throw new NotFoundException('resource'));
