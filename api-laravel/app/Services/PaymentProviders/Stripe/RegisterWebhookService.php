<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;

/**
 * Port of Rails' PaymentProviders::Stripe::RegisterWebhookService —
 * provisions the Lago-managed Stripe webhook endpoint
 * (POST /v1/webhook_endpoints with the org's secret key) and stores the
 * endpoint id + signing secret on the provider (webhook_id / webhook_secret
 * settings). The destination is
 * {LAGO_API_URL}/webhooks/stripe/{organization_id}?code={provider.code}.
 *
 * Rails rescues Stripe errors here (a failed registration must not fail the
 * connection): AuthenticationError / PermissionError / InvalidRequestError
 * deliver the payment_provider.error webhook and return a success result.
 */
class RegisterWebhookService extends BaseService
{
    public function __construct(
        private readonly PaymentProvider $paymentProvider,
        private readonly string $version = Client::API_VERSION,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('payment_provider');
        $provider = $this->paymentProvider;

        $params = [
            'url' => $this->webhookEndpointDestination(),
            'enabled_events' => PaymentProvider::STRIPE_WEBHOOKS_EVENTS,
            'api_version' => $this->version,
        ];

        try {
            $client = new Client((string) $provider->secretKey());
            $webhookEndpoint = $client->call('post', '/v1/webhook_endpoints', $params);
        } catch (StripeError $e) {
            $this->deliverErrorWebhook('payment_provider.register_webhook', $e);

            return $result;
        }

        $provider->setWebhookId($webhookEndpoint['id'] ?? null);
        $provider->setWebhookSecret($webhookEndpoint['secret'] ?? null);
        $provider->save();

        $result->payment_provider = $provider;

        return $result;
    }

    private function webhookEndpointDestination(): string
    {
        $baseUrl = mb_rtrim((string) env('LAGO_API_URL', 'http://localhost:3000'), '/');

        return $baseUrl.'/webhooks/stripe/'.$this->paymentProvider->organization_id
            .'?code='.urlencode((string) $this->paymentProvider->code);
    }

    private function deliverErrorWebhook(string $action, StripeError $error): void
    {
        SendWebhookJob::performLater('payment_provider.error', $this->paymentProvider, [
            'provider_error' => [
                'source' => 'stripe',
                'action' => $action,
                'message' => $error->getMessage(),
                'code' => $error->code(),
            ],
        ]);
    }
}
