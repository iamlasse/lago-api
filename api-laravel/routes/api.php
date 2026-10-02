<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\CustomersController;
use App\Http\Controllers\Api\V1\PlansController;
use App\Http\Controllers\Api\V1\OrganizationsController;
use App\Http\Controllers\Api\V1\Plans\ChargesController;
use App\Http\Controllers\Api\V1\Plans\FixedChargesController;
use App\Http\Controllers\Api\V1\Plans\Charges\FiltersController;

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
|   checkout_url, portal_url) — the events store is a later milestone;
| - the customers nested subresources (invoices, subscriptions,
|   applied_coupons, wallets, ...).
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
        Route::get('plans', [PlansController::class, 'index']);
        Route::post('plans', [PlansController::class, 'create']);

        Route::get('plans/{plan_code}/charges', [ChargesController::class, 'index']);
        Route::post('plans/{plan_code}/charges', [ChargesController::class, 'create']);
        Route::get('plans/{plan_code}/charges/{code}', [ChargesController::class, 'show']);
        Route::put('plans/{plan_code}/charges/{code}', [ChargesController::class, 'update']);
        Route::patch('plans/{plan_code}/charges/{code}', [ChargesController::class, 'update']);
        Route::delete('plans/{plan_code}/charges/{code}', [ChargesController::class, 'destroy']);

        Route::get('plans/{plan_code}/charges/{charge_code}/filters', [FiltersController::class, 'index']);
        Route::get('plans/{plan_code}/charges/{charge_code}/filters/{id}', [FiltersController::class, 'show']);

        Route::get('plans/{plan_code}/fixed_charges', [FixedChargesController::class, 'index']);
        Route::post('plans/{plan_code}/fixed_charges', [FixedChargesController::class, 'create']);
        Route::get('plans/{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'show']);
        Route::put('plans/{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'update']);
        Route::patch('plans/{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'update']);
        Route::delete('plans/{plan_code}/fixed_charges/{code}', [FixedChargesController::class, 'destroy']);

        Route::get('plans/{code}', [PlansController::class, 'show'])->where('code', '.+');
        Route::put('plans/{code}', [PlansController::class, 'update'])->where('code', '.+');
        Route::patch('plans/{code}', [PlansController::class, 'update'])->where('code', '.+');
        Route::delete('plans/{code}', [PlansController::class, 'destroy'])->where('code', '.+');


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
