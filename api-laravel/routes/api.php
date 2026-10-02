<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\CustomersController;
use App\Http\Controllers\Api\V1\OrganizationsController;

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
