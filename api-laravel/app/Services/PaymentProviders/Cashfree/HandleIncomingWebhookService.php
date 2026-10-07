<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Cashfree;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\PaymentProviders\FindService;
use App\Jobs\PaymentProviders\CashfreeHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Cashfree::HandleIncomingWebhookService —
 * verifies the X-Cashfree-Signature header: base64(HMAC-SHA256(secret,
 * "{timestamp}{body}")) with the provider's client_secret — then queues
 * the raw event for handling. A provider lookup failure is returned
 * untouched (the controller only maps "webhook_error" failures to a 400).
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly string $body,
        private readonly ?string $timestamp,
        private readonly ?string $signature,
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
            paymentProviderType: 'cashfree',
        );

        if ($paymentProviderResult->failure()) {
            return $paymentProviderResult;
        }

        $secretKey = (string) ($paymentProviderResult->payment_provider->clientSecret() ?? '');
        $generatedSignature = base64_encode(hash_hmac('sha256', ($this->timestamp ?? '').$this->body, $secretKey, true));

        if ($this->signature === null || ! hash_equals($generatedSignature, $this->signature)) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid signature');
        }

        dispatch(new \App\Jobs\PaymentProviders\CashfreeHandleEventJob($paymentProviderResult->payment_provider->organization, $this->body));

        $result->event = $this->body;

        return $result;
    }
}
