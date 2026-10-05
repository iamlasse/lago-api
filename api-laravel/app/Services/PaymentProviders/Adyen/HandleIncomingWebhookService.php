<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Failures\ServiceFailure;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\AdyenHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Adyen::HandleIncomingWebhookService —
 * finds the organization and its adyen provider, verifies the notification
 * item's HMAC signature against the provider's hmac_key, and queues the
 * event handling with the (permitted) NotificationRequestItem JSON.
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        /** @var array<string, mixed> the NotificationRequestItem */
        private readonly array $body,
        private readonly ?string $code = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        $organization = Organization::query()->find($this->organizationId);
        if ($organization === null) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Organization not found');
        }

        $paymentProviderResult = FindService::call(
            organizationId: $this->organizationId,
            code: $this->code,
            paymentProviderType: 'adyen',
        );

        if ($paymentProviderResult->failure()) {
            $error = $paymentProviderResult->getError();

            if ($error instanceof ServiceFailure) {
                return $result->serviceFailure(
                    code: 'webhook_error',
                    message: $error->errorMessage,
                );
            }

            return $paymentProviderResult;
        }

        $hmacKey = (string) ($paymentProviderResult->payment_provider->hmacKey() ?? '');

        $validation = ValidateIncomingWebhookService::call(
            body: $this->body,
            hmacKey: $hmacKey,
        );

        if ($validation->failure()) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        AdyenHandleEventJob::dispatch(
            $organization,
            json_encode($this->body, JSON_THROW_ON_ERROR),
        );

        $result->event = $this->body;

        return $result;
    }
}
