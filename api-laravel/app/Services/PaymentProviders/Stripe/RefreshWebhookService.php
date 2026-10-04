<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;

/**
 * Port of Rails' PaymentProviders::Stripe::RefreshWebhookService — re-points
 * the managed Stripe webhook endpoint (PATCH /v1/webhook_endpoints/{id})
 * after the provider code changed (the destination embeds ?code=).
 */
class RefreshWebhookService extends BaseService
{
    public function __construct(
        private readonly PaymentProvider $paymentProvider,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();
        $provider = $this->paymentProvider;

        $params = [
            'url' => $this->webhookEndpointDestination(),
            'enabled_events' => PaymentProvider::STRIPE_WEBHOOKS_EVENTS,
        ];

        try {
            $client = new Client((string) $provider->secretKey());
            $client->call('post', '/v1/webhook_endpoints/'.$provider->webhookId(), $params);
        } catch (StripeError $e) {
            SendWebhookJob::performLater('payment_provider.error', $provider, [
                'provider_error' => [
                    'source' => 'stripe',
                    'action' => 'payment_provider.register_webhook',
                    'message' => $e->getMessage(),
                    'code' => $e->code(),
                ],
            ]);
        }

        return $result;
    }

    private function webhookEndpointDestination(): string
    {
        $baseUrl = mb_rtrim((string) env('LAGO_API_URL', 'http://localhost:3000'), '/');

        return $baseUrl.'/webhooks/stripe/'.$this->paymentProvider->organization_id
            .'?code='.urlencode((string) $this->paymentProvider->code);
    }
}
