<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\InboundWebhook;
use App\Services\Failures\ServiceFailure;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentProviders\Stripe\HandleIncomingWebhookService;
use App\Services\PaymentProviders\Stripe\ValidateIncomingWebhookService;

/**
 * Port of Rails' WebhooksController (app/controllers/webhooks_controller.rb)
 * — provider webhook entrypoints at POST /webhooks/{provider}/{organization_id}.
 *
 * Stripe flow (Rails: InboundWebhooks::CreateService): resolve the org's
 * stripe provider by code, verify the Stripe-Signature header against the
 * provider's webhook secret, persist the raw payload as an InboundWebhook,
 * and queue the event handling. Any failure (unknown organization /
 * provider, bad signature, invalid payload) answers HTTP 400 (Rails:
 * `head(:bad_request) unless result.success?`).
 *
 * TODO(port) the other provider webhook legs (Rails entry points):
 *  - cashfree   -> PaymentProviders::Cashfree::HandleIncomingWebhookService
 *                  (X-Cashfree-Timestamp + X-Cashfree-Signature, base64 HMAC-SHA256)
 *  - flutterwave-> PaymentProviders::Flutterwave::HandleIncomingWebhookService
 *                  (verif-hash header equality)
 *  - gocardless -> PaymentProviders::Gocardless::HandleIncomingWebhookService
 *                  (Webhook-Signature, HMAC of body with Ed25519/HMAC scheme)
 *  - adyen      -> PaymentProviders::Adyen::HandleIncomingWebhookService
 *                  (HMAC of the notification item, renders "[accepted]")
 *  - moneyhash  -> InboundWebhooks::CreateService with source :moneyhash
 *                  (MoneyHash-Signature header)
 * Until ported each stub mirrors Rails' flow up to the provider lookup: an
 * unconfigured provider raises the "payment_provider_not_found" failure
 * (Rails: raise_if_error! -> 500).
 */
class WebhookController extends Controller
{
    public function stripe(Request $request, string $organizationId): Response
    {
        $ok = $this->verifyStripeAndStore(
            organizationId: $organizationId,
            code: $request->query('code'),
            payload: (string) $request->getContent(),
            signature: $request->header('Stripe-Signature'),
            eventType: (string) $request->query('type', ''),
        );

        return new Response(status: $ok ? 200 : 400);
    }

    /** Verifies the signature and persists + queues the event. True on success. */
    private function verifyStripeAndStore(
        string $organizationId,
        ?string $code,
        string $payload,
        ?string $signature,
        string $eventType,
    ): bool {
        try {
            $providerResult = FindService::call(
                organizationId: $organizationId,
                code: $code,
                paymentProviderType: 'stripe',
            )->raiseIfError();

            ValidateIncomingWebhookService::call(
                payload: $payload,
                signature: $signature,
                provider: $providerResult->payment_provider,
            )->raiseIfError();

            $inboundWebhook = InboundWebhook::create([
                'organization_id' => $organizationId,
                'source' => 'stripe',
                'code' => $code,
                'payload' => $payload,
                'signature' => $signature,
                'event_type' => $eventType,
            ]);

            HandleIncomingWebhookService::call(inboundWebhook: $inboundWebhook)->raiseIfError();

            return true;
        } catch (ServiceFailure|Throwable) {
            return false;
        }
    }
}
