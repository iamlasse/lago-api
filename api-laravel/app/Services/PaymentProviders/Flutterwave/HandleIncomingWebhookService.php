<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Flutterwave;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\FlutterwaveHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Flutterwave::HandleIncomingWebhookService —
 * the verif-hash header must equal the provider's webhook secret (stored in
 * secrets, generated on create); the raw body is then queued for handling.
 * A provider lookup failure is returned untouched (the controller only maps
 * "webhook_error" failures to a 400).
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly string $body,
        private readonly ?string $secret,
        private readonly ?string $code = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        $paymentProviderResult = FindService::call(
            organizationId: $this->organizationId,
            code: $this->code,
            paymentProviderType: 'flutterwave',
        );

        if ($paymentProviderResult->failure()) {
            return $paymentProviderResult;
        }

        $webhookSecret = $paymentProviderResult->payment_provider->flutterwaveWebhookSecret();

        if ($webhookSecret === null || $webhookSecret === '') {
            return $result->serviceFailure(code: 'webhook_error', message: 'Webhook secret is missing');
        }

        if ($webhookSecret !== $this->secret) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid webhook secret');
        }

        FlutterwaveHandleEventJob::dispatch(
            $paymentProviderResult->payment_provider->organization,
            $this->body,
        );

        $result->event = $this->body;

        return $result;
    }
}
