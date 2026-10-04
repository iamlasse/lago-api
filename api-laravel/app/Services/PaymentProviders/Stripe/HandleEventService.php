<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Stripe;

use Throwable;
use JsonException;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\Failures\FailedResult;
use App\Services\PaymentProviders\Stripe\Webhooks\PaymentIntentSucceededService;
use App\Services\PaymentProviders\Stripe\Webhooks\PaymentIntentPaymentFailedService;

/**
 * Port of Rails' PaymentProviders::Stripe::HandleEventService — routes a
 * verified Stripe event to its handler.
 *
 * Ported handlers:
 *  - payment_intent.succeeded    -> Webhooks\PaymentIntentSucceededService
 *  - payment_intent.payment_failed / payment_intent.canceled
 *                               -> Webhooks\PaymentIntentPaymentFailedService
 *
 * TODO(port): the remaining subscribed events (Rails entry points):
 *  - setup_intent.succeeded -> Stripe::Webhooks::SetupIntentSucceededService
 *    (stores the payment method + sets the customer default);
 *  - customer.updated -> Stripe::Webhooks::CustomerUpdatedService;
 *  - charge.dispute.closed -> Stripe::Webhooks::ChargeDisputeClosedService;
 *  - customer_cash_balance_transaction.created ->
 *    Stripe::Webhooks::CustomerCashBalanceTransactionCreatedService;
 *  - payment_method.detached -> PaymentProviderCustomers::StripeService
 *    (:delete_payment_method);
 *  - charge.refund.updated -> CreditNotes::Refunds::StripeService.
 *
 * Subscribed-but-unhandled types log a warning (Rails: logger.warn) and
 * succeed. A NotFoundFailure on a sandbox event (livemode false) is
 * swallowed with a warning; on live events it re-raises.
 */
class HandleEventService extends BaseService
{
    /** @var array<string, class-string<BaseService>> */
    public const EVENT_MAPPING = [
        'payment_intent.succeeded' => PaymentIntentSucceededService::class,
        'payment_intent.payment_failed' => PaymentIntentPaymentFailedService::class,
        'payment_intent.canceled' => PaymentIntentPaymentFailedService::class,
    ];

    public function __construct(
        private readonly Organization $organization,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($event)) {
                throw new JsonException('Invalid event');
            }
        } catch (Throwable) {
            return $result->serviceFailure(code: 'webhook_error', message: 'Invalid payload');
        }

        $type = (string) ($event['type'] ?? '');

        if (! in_array($type, ['setup_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.succeeded', 'payment_intent.canceled', 'payment_method.detached', 'charge.refund.updated', 'customer.updated', 'charge.dispute.closed', 'customer_cash_balance_transaction.created'], true)) {
            Log::warning("Unexpected stripe event type: {$type}");

            return $result;
        }

        $handlerClass = self::EVENT_MAPPING[$type] ?? null;

        if ($handlerClass === null) {
            Log::warning("Stripe event type is not handled yet: {$type}");

            return $result;
        }

        try {
            $handlerClass::call(
                organization: $this->organization,
                event: $event,
            )->raiseIfError();
        } catch (FailedResult $e) {
            $livemode = (bool) ($event['livemode'] ?? false);

            if ($livemode) {
                throw $e;
            }

            Log::warning('Stripe resource not found: '.$e->getMessage().' JSON: '.$this->eventJson);
        }

        return $result;
    }
}
