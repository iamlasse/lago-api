<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\PlansController;
use App\Http\Controllers\Api\V1\TaxesController;
use App\Http\Controllers\Api\V1\EventsController;
use App\Http\Controllers\Api\V1\CouponsController;
use App\Http\Controllers\Api\V1\WalletsController;
use App\Http\Controllers\Api\V1\InvoicesController;
use App\Http\Controllers\Api\V1\CustomersController;
use App\Http\Controllers\Api\V1\OrganizationsController;
use App\Http\Controllers\Api\V1\Plans\ChargesController;
use App\Http\Controllers\Api\V1\SubscriptionsController;
use App\Http\Controllers\Api\V1\AppliedCouponsController;
use App\Http\Controllers\Api\V1\BillableMetricsController;
use App\Http\Controllers\Api\V1\WebhookEndpointsController;
use App\Http\Controllers\Api\V1\Plans\FixedChargesController;
use App\Http\Controllers\Api\V1\WalletTransactionsController;
use App\Http\Controllers\Api\V1\Plans\Charges\FiltersController;
use App\Http\Controllers\Api\V1\Customers\WalletsController as CustomerWalletsController;
use App\Http\Controllers\Api\V1\Customers\AppliedCouponsController as CustomerAppliedCouponsController;

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
| - customers usage endpoints (current_usage/projected_usage/past_usage,
|   checkout_url, portal_url) — the events store is ported (M2 groundwork),
|   the usage/aggregation services are not yet;
| - the remaining customers nested subresources (invoices, subscriptions,
|   credit_notes, payments, payment_requests, payment_methods, and the
|   wallets alerts/metadata subresources).
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
        Route::prefix('billable_metrics')->as('billable_metrics:')->group(function () {
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

        // -- taxes -----------------------------------------------------------------
        // Keyed by code like billable metrics (Rails: `resources :taxes,
        // param: :code, code: /.*/`).
        Route::prefix('taxes')->as('taxes:')->group(function () {
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
        Route::prefix('webhook_endpoints')->as('webhook_endpoints:')->group(function () {
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
        // Not registered yet (dependencies do not exist): entitlements and
        // metadata subresources (no ported services), charge filter
        // create/update/destroy (ChargeFilters::Create/Update/DestroyService
        // not ported — only index/show need no service).
        Route::prefix('plans')->as('plans:')->group(function () {
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

            Route::get('{code}', [PlansController::class, 'show'])->where('code', '.+');
            Route::put('{code}', [PlansController::class, 'update'])->where('code', '.+');
            Route::patch('{code}', [PlansController::class, 'update'])->where('code', '.+');
            Route::delete('{code}', [PlansController::class, 'destroy'])->where('code', '.+');
        });

        // -- subscriptions ----------------------------------------------------
        // DELETE on a subscription never destroys the row: it terminates it
        // (Subscriptions\TerminateService).
        //
        // Not registered yet (dependencies do not exist): the nested
        // subresources lifetime_usage, alerts, entitlements, charges and
        // fixed_charges (no ported controllers/services), and the
        // /customers/:external_id/subscriptions index.
        Route::prefix('subscriptions')->as('subscriptions:')->group(function () {
            Route::get('', [SubscriptionsController::class, 'index']);
            Route::post('', [SubscriptionsController::class, 'create']);
            Route::get('{external_id}', [SubscriptionsController::class, 'show'])
                ->where('external_id', '.+');
            Route::put('{external_id}', [SubscriptionsController::class, 'update'])
                ->where('external_id', '.+');
            Route::patch('{external_id}', [SubscriptionsController::class, 'update'])
                ->where('external_id', '.+');
            Route::delete('{external_id}', [SubscriptionsController::class, 'terminate'])
                ->where('external_id', '.+');
        });

        // -- invoices ---------------------------------------------------------
        // Keyed by uuid id (Rails: resources :invoices — the default param
        // constraint applies; uuids contain no dots). Member actions per
        // rest.json: finalize/refresh are PUT (Rails' non-RESTful draw),
        // void/retry/lose_dispute/download_* are POST.
        //
        // Not registered yet (dependencies do not exist): retry_payment
        // (Invoices::Payments::RetryService), payment_url
        // (GeneratePaymentUrlService), resend_email (Emails::ResendService)
        // and sync_salesforce_id (SyncSalesforceIdService) — each has its
        // ledger row and lands with its milestone. Preview IS registered but
        // premium-gated (Rails: PremiumFeatureOnly → 403 feature_unavailable
        // in the OSS image).
        Route::prefix('invoices')->as('invoices:')->group(function () {
            Route::get('', [InvoicesController::class, 'index']);
            Route::post('', [InvoicesController::class, 'create']);
            Route::post('preview', [InvoicesController::class, 'preview']);

            Route::put('{id}/finalize', [InvoicesController::class, 'finalize']);
            Route::put('{id}/refresh', [InvoicesController::class, 'refresh']);

            Route::post('{id}/void', [InvoicesController::class, 'void']);
            Route::post('{id}/retry', [InvoicesController::class, 'retry']);
            Route::post('{id}/lose_dispute', [InvoicesController::class, 'loseDispute']);
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

        // -- customers nested subresources --------------------------------------
        // The external_id segment carries the `.+` constraint (see the
        // customers show/destroy routes below); wallets are keyed by CODE,
        // which is only unique among active wallets.
        //
        // Not registered yet (dependencies do not exist): the invoices,
        // subscriptions, credit_notes, payments, payment_requests,
        // payment_methods subresources, the wallets alerts/metadata
        // subresources, and the applied_coupons destroy route's controller
        // actions beyond index/destroy themselves (coupons slice).
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
            });

        // customers and subscriptions are looked up by external_id, which
        // may contain dots. Rails constrains those params with /[^\/]+/ (a
        // bare :external_id would truncate the value at the format
        // separator); the Laravel equivalent is the `.+` constraint below.
        Route::get('customers/{external_id}', [CustomersController::class, 'show'])
            ->where('external_id', '.+');
        Route::delete('customers/{external_id}', [CustomersController::class, 'destroy'])
            ->where('external_id', '.+');
    });
};

Route::prefix('v1')->group($sharedApi);

Route::prefix('v2')->middleware(SetBetaHeader::class)->group($sharedApi);

// Unmatched /api/* routes render the Lago 404 envelope (Rails:
// ApplicationController#not_found -> {"status":404,"error":"Not Found",
// "code":"resource_not_found"}).
Route::fallback(fn (): never => throw new NotFoundException('resource'));
