<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookController;

/**
 * Provider webhook routes (Rails: config/routes.rb `resources :webhooks`).
 * Signature-protected, no API-key auth — mirrors Rails where these live
 * outside the api namespace.
 *
 * Ported: stripe (signature verification + payment status transitions).
 *
 * TODO(port) the other providers (Rails entry points in
 * WebhooksController + PaymentProviders::{Provider}::HandleIncomingWebhookService):
 *  - POST /webhooks/cashfree/{organization_id}    (X-Cashfree-Signature)
 *  - POST /webhooks/flutterwave/{organization_id} (verif-hash)
 *  - POST /webhooks/gocardless/{organization_id}  (Webhook-Signature)
 *  - POST /webhooks/adyen/{organization_id}       (HMAC notification item, renders "[accepted]")
 *  - POST /webhooks/moneyhash/{organization_id}   (MoneyHash-Signature)
 */
Route::post('stripe/{organization_id}', [WebhookController::class, 'stripe'])
    ->name('stripe');
