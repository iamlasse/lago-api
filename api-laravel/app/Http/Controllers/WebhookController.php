<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\InboundWebhook;
use App\Services\Failures\ServiceFailure;
use App\Services\PaymentProviders\FindService;
use App\Services\PaymentProviders\Adyen\HandleIncomingWebhookService as AdyenHandleIncomingWebhookService;
use App\Services\PaymentProviders\Cashfree\HandleIncomingWebhookService as CashfreeHandleIncomingWebhookService;
use App\Services\PaymentProviders\Flutterwave\HandleIncomingWebhookService as FlutterwaveHandleIncomingWebhookService;
use App\Services\PaymentProviders\Gocardless\HandleIncomingWebhookService as GocardlessHandleIncomingWebhookService;
use App\Services\PaymentProviders\Moneyhash\HandleIncomingWebhookService as MoneyhashHandleIncomingWebhookService;
use App\Services\PaymentProviders\Moneyhash\ValidateIncomingWebhookService as MoneyhashValidateIncomingWebhookService;
use App\Services\PaymentProviders\Stripe\HandleIncomingWebhookService;
use App\Services\PaymentProviders\Stripe\ValidateIncomingWebhookService;

/**
 * Port of Rails' WebhooksController (app/controllers/webhooks_controller.rb)
 * — provider webhook entrypoints at POST /webhooks/{provider}/{organization_id}.
 *
 * Stripe + moneyhash flow (Rails: InboundWebhooks::CreateService): resolve
 * the org's provider by code, verify the signature header against the
 * provider's secret, persist the payload as an InboundWebhook, and queue
 * the event handling. Signature failures answer HTTP 400.
 *
 * cashfree / flutterwave / gocardless verify inline in their
 * HandleIncomingWebhookService (Rails: the service takes the raw body +
 * headers): only "webhook_error" ServiceFailures answer 400 — any other
 * failure re-raises (HTTP 500), like Rails' `result.raise_if_error!`.
 *
 * Adyen verifies the notification item's HMAC and renders "[accepted]".
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

    public function cashfree(Request $request, string $organizationId): Response
    {
        try {
            $result = CashfreeHandleIncomingWebhookService::call(
                organizationId: $organizationId,
                code: $request->query('code'),
                body: (string) $request->getContent(),
                timestamp: $request->header('X-Cashfree-Timestamp') ?? $request->header('X-Webhook-Timestamp'),
                signature: $request->header('X-Cashfree-Signature') ?? $request->header('X-Webhook-Signature'),
            );

            if ($result->failure()) {
                $error = $result->getError();

                if ($error instanceof ServiceFailure && $error->code === 'webhook_error') {
                    return new Response(status: 400);
                }

                $result->raiseIfError();
            }

            return new Response(status: 200);
        } catch (ServiceFailure|Throwable) {
            return new Response(status: 500);
        }
    }

    public function flutterwave(Request $request, string $organizationId): Response
    {
        try {
            $result = FlutterwaveHandleIncomingWebhookService::call(
                organizationId: $organizationId,
                code: $request->query('code'),
                body: (string) $request->getContent(),
                secret: $request->header('verif-hash'),
            );

            if ($result->failure()) {
                $error = $result->getError();

                if ($error instanceof ServiceFailure && $error->code === 'webhook_error') {
                    return new Response(status: 400);
                }

                $result->raiseIfError();
            }

            return new Response(status: 200);
        } catch (ServiceFailure|Throwable) {
            return new Response(status: 500);
        }
    }

    public function gocardless(Request $request, string $organizationId): Response
    {
        try {
            $result = GocardlessHandleIncomingWebhookService::call(
                organizationId: $organizationId,
                code: $request->query('code'),
                body: (string) $request->getContent(),
                signature: $request->header('Webhook-Signature'),
            );

            if ($result->failure()) {
                $error = $result->getError();

                if ($error instanceof ServiceFailure && $error->code === 'webhook_error') {
                    return new Response(status: 400);
                }

                $result->raiseIfError();
            }

            return new Response(status: 200);
        } catch (ServiceFailure|Throwable) {
            return new Response(status: 500);
        }
    }

    public function adyen(Request $request, string $organizationId): Response
    {
        try {
            // Rails: adyen_params — the permitted NotificationRequestItem
            // (the first notification item) is what flows through.
            $body = (array) $request->json('notificationItems.0.NotificationRequestItem', []);

            $result = AdyenHandleIncomingWebhookService::call(
                organizationId: $organizationId,
                code: $request->query('code'),
                body: $body,
            );

            if ($result->failure()) {
                $error = $result->getError();

                if ($error instanceof ServiceFailure && $error->code === 'webhook_error') {
                    return new Response(status: 400);
                }

                $result->raiseIfError();
            }

            // Rails: render(json: "[accepted]") — the raw string body.
            return new Response('[accepted]', 200, ['Content-Type' => 'application/json']);
        } catch (ServiceFailure|Throwable) {
            return new Response(status: 500);
        }
    }

    public function moneyhash(Request $request, string $organizationId): Response
    {
        $ok = $this->verifyMoneyhashAndStore(
            organizationId: $organizationId,
            code: $request->query('code'),
            payload: (string) $request->getContent(),
            signature: $request->header('MoneyHash-Signature'),
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

    /** Verifies the signature and persists + queues the event. True on success. */
    private function verifyMoneyhashAndStore(
        string $organizationId,
        ?string $code,
        string $payload,
        ?string $signature,
        string $eventType,
    ): bool {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new \JsonException('Invalid payload');
            }

            $providerResult = FindService::call(
                organizationId: $organizationId,
                code: $code,
                paymentProviderType: 'moneyhash',
            )->raiseIfError();

            MoneyhashValidateIncomingWebhookService::call(
                payload: $decoded,
                signature: $signature,
                provider: $providerResult->payment_provider,
            )->raiseIfError();

            $inboundWebhook = InboundWebhook::create([
                'organization_id' => $organizationId,
                'source' => 'moneyhash',
                'code' => $code,
                'payload' => $payload,
                'signature' => $signature,
                'event_type' => $eventType,
            ]);

            MoneyhashHandleIncomingWebhookService::call(inboundWebhook: $inboundWebhook)->raiseIfError();

            return true;
        } catch (ServiceFailure|Throwable) {
            return false;
        }
    }
}
