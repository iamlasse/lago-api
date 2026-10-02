<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\SetBetaHeader;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\V1\PlaceholderController;

/*
|--------------------------------------------------------------------------
| REST API v1 (mirrored at v2)
|--------------------------------------------------------------------------
|
| The Rails route table is drawn from a live `rails routes` dump into
| tests/inventory/rest.yaml; routes are added here per-port with their
| tests. The $sharedApi block below is the entire v1 table and is mounted
| verbatim under both /api/v1 and /api/v2 — the v2 mount carries the
| `X-Lago-Endpoint-Status: beta` header on every response (Rails keys it
| on the request path inside Api::BaseController, including error
| responses).
*/

$sharedApi = function (): void {
    // M1 scaffolding placeholder so the route groups, the api-key
    // middleware and the error envelopes are exercisable before the first
    // real controllers are ported. Deleted when real routes land.
    Route::get('placeholder', [PlaceholderController::class, 'index'])->middleware('lago.auth');
    Route::post('placeholder', [PlaceholderController::class, 'store'])->middleware('lago.auth');

    // ROUTE PARAM CONSTRAINT (apply when these routes are added):
    // customers and subscriptions are looked up by external_id, which may
    // contain dots. Rails constrains those params with /[^\/]+/ (a bare
    // :external_id would truncate the value at the format separator); the
    // Laravel equivalent for external_id-bearing routes is:
    //     ->where('external_id', '.+')
};

Route::prefix('v1')->group($sharedApi);

Route::prefix('v2')->middleware(SetBetaHeader::class)->group($sharedApi);

// Unmatched /api/* routes render the Lago 404 envelope (Rails:
// ApplicationController#not_found -> {"status":404,"error":"Not Found",
// "code":"resource_not_found"}).
Route::fallback(fn (): never => throw new NotFoundException('resource'));
