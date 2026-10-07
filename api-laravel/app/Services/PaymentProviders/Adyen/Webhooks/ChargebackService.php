<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Adyen\Webhooks;

use Throwable;
use App\Models\Payment;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Invoices\LoseDisputeService;

/**
 * Port of Rails' Adyen::Webhooks::ChargebackService — a LOST dispute (and
 * only a lost one) marks the payment's invoice as dispute-lost at the
 * event's date.
 */
class ChargebackService extends BaseService
{
    public function __construct(
        private readonly string $organizationId,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

        $status = $event['additionalData']['disputeStatus'] ?? null;
        $reason = $event['reason'] ?? null;
        $providerPaymentId = $event['pspReference'] ?? null;

        $payment = Payment::query()->where('provider_payment_id', $providerPaymentId)->first();

        if ($payment === null) {
            return $result->notFoundFailure('adyen_payment');
        }

        if ($status === 'Lost' && ($event['success'] ?? null) === 'true') {
            LoseDisputeService::call(
                invoice: $payment->payable,
                paymentDisputeLostAt: self::paymentDisputeLostAt($event),
                reason: is_string($reason) ? $reason : null,
            )->raiseIfError();
        }

        return $result;
    }

    /** Rails: Time.zone.parse(event["eventDate"]). */
    private static function paymentDisputeLostAt(array $event): ?string
    {
        $eventDate = $event['eventDate'] ?? null;

        if (! is_string($eventDate) || $eventDate === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Date::parse($eventDate)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
