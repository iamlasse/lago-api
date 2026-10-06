<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Gocardless;

use Throwable;
use LogicException;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Log;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\Payments\GocardlessService;

/**
 * Port of Rails' PaymentProviders::Gocardless::HandleEventService — the
 * per-event dispatcher over GoCardless webhook payloads:
 *  - "payments" events in the payment actions set move the payment status
 *    (Invoices::Payments::GocardlessService#update_payment_status);
 *  - "refunds" events are TODO(port) (CreditNotes::Refunds::GocardlessService
 *    — the credit-note refunds milestone);
 *  - "mandates" events create or (API-originated) cancel the local payment
 *    method;
 *  - a not-found resource is logged and swallowed (Rails' NotFoundFailure
 *    rescue returns a fresh successful result); an unknown
 *    lago_payable_type raises (Rails' NameError).
 */
class HandleEventService extends BaseService
{
    public const PAYMENT_ACTIONS = ['paid_out', 'failed', 'cancelled', 'customer_approval_denied', 'charged_back'];

    public const REFUND_ACTIONS = ['created', 'funds_returned', 'paid', 'refund_settled', 'failed'];

    public const MANDATE_CREATED_ACTIONS = ['created'];

    public const MANDATE_CANCELLED_ACTIONS = ['cancelled'];

    public function __construct(
        private readonly PaymentProvider $paymentProvider,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            return $this->handle($event);
        } catch (FailedResult $e) {
            if (! $e instanceof \App\Services\Failures\NotFoundFailure) {
                throw $e;
            }

            // Rails: rescue BaseService::NotFoundFailure -> warn + fresh result.
            Log::warning('GoCardless resource not found: '.$e->getMessage().'. JSON: '.$this->eventJson);

            return static::makeResult();
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * Rails: payment_service_klass — the Invoice service is ported; a
     * PaymentRequest payable has its own (unported) services and an unknown
     * type is Rails' NameError.
     *
     * @param  array<string, mixed>  $event
     */
    private static function paymentServiceClass(array $event): string
    {
        $payableType = $event['metadata']['lago_payable_type'] ?? 'Invoice';

        if ($payableType === 'Invoice') {
            return GocardlessService::class;
        }

        throw new LogicException("Invalid lago_payable_type: {$payableType}");
    }

    /** Rails: api_originated_event? — details.origin == "api". */
    private static function apiOriginatedEvent(array $event): bool
    {
        return ($event['details']['origin'] ?? null) === 'api';
    }

    /** @param array<string, mixed> $event */
    private function handle(array $event): BaseResult
    {
        $result = static::makeResult();

        $resourceType = (string) ($event['resource_type'] ?? '');
        $action = (string) ($event['action'] ?? '');

        switch ($resourceType) {
            case 'payments':
                if (in_array($action, self::PAYMENT_ACTIONS, true)) {
                    self::paymentServiceClass($event)::updatePaymentStatus(
                        providerPaymentId: (string) ($event['links']['payment'] ?? ''),
                        status: $action,
                    )->raiseIfError();
                }

                return $result;

            case 'refunds':
                if (in_array($action, self::REFUND_ACTIONS, true)) {
                    \App\Services\CreditNotes\Refunds\GocardlessService::updateStatus(
                        providerRefundId: (string) ($event['links']['refund'] ?? ''),
                        status: $action,
                        metadata: $event['metadata'] ?? [],
                    )->raiseIfError();
                }

                return $result;

            case 'mandates':
                if (in_array($action, self::MANDATE_CREATED_ACTIONS, true)) {
                    Webhooks\MandateCreatedService::callBang(
                        paymentProvider: $this->paymentProvider,
                        mandateId: (string) ($event['links']['mandate'] ?? ''),
                    );
                } elseif (in_array($action, self::MANDATE_CANCELLED_ACTIONS, true) && self::apiOriginatedEvent($event)) {
                    Webhooks\MandateCancelledService::callBang(
                        paymentProvider: $this->paymentProvider,
                        mandateId: (string) ($event['links']['mandate'] ?? ''),
                    );
                }

                return $result;

            default:
                return $result;
        }
    }
}
