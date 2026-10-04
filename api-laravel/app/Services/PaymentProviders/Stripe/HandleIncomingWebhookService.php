<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use Throwable;
use JsonException;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Jobs\PaymentProviders\StripeHandleEventJob;

/**
 * Port of Rails' PaymentProviders::Stripe::HandleIncomingWebhookService —
 * takes the verified raw payload (InboundWebhooks::CreateService already
 * validated the signature and persisted the InboundWebhook row) and queues
 * the event handling. An unparseable payload fails with "webhook_error"
 * (the route answers 400).
 */
class HandleIncomingWebhookService extends BaseService
{
    public function __construct(
        private readonly \App\Models\InboundWebhook $inboundWebhook,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        try {
            $event = json_decode((string) $this->inboundWebhook->payload, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($event)) {
                throw new JsonException('Invalid payload');
            }
        } catch (Throwable) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid payload');
        }

        StripeHandleEventJob::dispatch(
            $this->inboundWebhook->organization,
            $event,
        );

        $result->event = $event;

        return $result;
    }
}
