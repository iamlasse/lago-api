<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;

/**
 * Provider webhook routes (Rails: config/routes.rb `resources :webhooks`).
 * Signature-protected, no API-key auth — mirrors Rails where these live
 * outside the api namespace.
 *
 * Signature schemes (Rails: WebhooksController +
 * PaymentProviders::{Provider}::HandleIncomingWebhookService):
 *  - stripe      Stripe-Signature (t/v1 HMAC of "{t}.{payload}", 300s tolerance)
 *  - cashfree    X-Cashfree-Signature, base64 HMAC-SHA256(secret,
 *                "{X-Cashfree-Timestamp}{body}") with the client secret
 *  - flutterwave verif-hash header equality with the provider's webhook secret
 *  - gocardless  Webhook-Signature, hex HMAC-SHA256 of the raw body
 *  - adyen       HMAC over the notification item's signed fields, rendered
 *                as "[accepted]"
 *  - moneyhash   MoneyHash-Signature (t/v3 HMAC of base64(payload)+t)
 */
Route::post('stripe/{organization_id}', [WebhookController::class, 'stripe'])
    ->name('stripe');
Route::post('cashfree/{organization_id}', [WebhookController::class, 'cashfree'])
    ->name('cashfree');
Route::post('flutterwave/{organization_id}', [WebhookController::class, 'flutterwave'])
    ->name('flutterwave');
Route::post('gocardless/{organization_id}', [WebhookController::class, 'gocardless'])
    ->name('gocardless');
Route::post('adyen/{organization_id}', [WebhookController::class, 'adyen'])
    ->name('adyen');
Route::post('moneyhash/{organization_id}', [WebhookController::class, 'moneyhash'])
    ->name('moneyhash');
