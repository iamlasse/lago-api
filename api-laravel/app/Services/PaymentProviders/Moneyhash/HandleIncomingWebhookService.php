<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Moneyhash;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\InboundWebhook;
use App\Services\Failures\ServiceFailure;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\MoneyhashHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Moneyhash::HandleIncomingWebhookService —
 * takes the persisted InboundWebhook (InboundWebhooks::CreateService already
 * validated the MoneyHash-Signature), finds the organization and its
 * moneyhash provider, and queues the event handling.
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly InboundWebhook $inboundWebhook,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        $organization = Organization::query()->find($this->inboundWebhook->organization_id);

        if ($organization === null) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Organization not found');
        }

        $paymentProviderResult = FindService::call(
            organizationId: $this->inboundWebhook->organization_id,
            code: $this->inboundWebhook->code,
            paymentProviderType: 'moneyhash',
        );

        if ($paymentProviderResult->failure()) {
            $error = $paymentProviderResult->getError();

            if ($error instanceof ServiceFailure) {
                return $result->serviceFailure(code: 'webhook_error', message: $error->errorMessage);
            }

            return $paymentProviderResult;
        }

        dispatch(new \App\Jobs\PaymentProviders\MoneyhashHandleEventJob($organization, (string) $this->inboundWebhook->payload));

        $result->event = $this->inboundWebhook->payload;

        return $result;
    }
}
