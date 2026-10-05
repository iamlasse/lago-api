<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\GocardlessHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Gocardless::HandleIncomingWebhookService —
 * verifies the Webhook-Signature header (hex HMAC-SHA256 of the raw body,
 * gocardless_pro's Webhook.parse), then queues one HandleEventJob per
 * parsed event. A provider lookup failure is returned untouched (the
 * controller only maps "webhook_error" failures to a 400; anything else
 * raises).
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly string $body,
        private readonly ?string $signature,
        private readonly ?string $code = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('events');

        $paymentProviderResult = FindService::call(
            organizationId: $this->organizationId,
            code: $this->code,
            paymentProviderType: 'gocardless',
        );

        if ($paymentProviderResult->failure()) {
            return $paymentProviderResult;
        }

        /** @var PaymentProvider $paymentProvider */
        $paymentProvider = $paymentProviderResult->payment_provider;

        $validation = ValidateIncomingWebhookService::call(
            body: $this->body,
            signatureHeader: $this->signature,
            webhookSecret: $paymentProvider->webhookSecret(),
        );

        if ($validation->failure()) {
            $error = $validation->getError();

            return $result->serviceFailure(
                code: 'webhook_error',
                message: $error instanceof \App\Services\Failures\ServiceFailure ? $error->errorMessage : 'Invalid signature',
            );
        }

        foreach ($validation->events as $event) {
            GocardlessHandleEventJob::dispatch(
                $paymentProvider->organization,
                $paymentProvider,
                json_encode($event, JSON_THROW_ON_ERROR),
            );
        }

        $result->events = $validation->events;

        return $result;
    }
}
