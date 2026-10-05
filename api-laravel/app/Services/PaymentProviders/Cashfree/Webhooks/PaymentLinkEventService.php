<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders\Cashfree\Webhooks;

use Throwable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Values\CashfreePayment;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\Payments\CashfreeService;

/**
 * Port of Rails' Cashfree::Webhooks::PaymentLinkEventService — a PAID
 * payment-link event moves the invoice payment status. The link notes'
 * lago_invoice_id / lago_payable_id is the provider payment id; the
 * lago_payable_type picks the payable's service (only the Invoice service
 * is ported — a PaymentRequest payable has its own unported services, an
 * unknown type is Rails' NameError). link_amount_paid is decimal-major;
 * Rails' Money.from_amount converts to cents.
 */
class PaymentLinkEventService extends BaseService
{
    public const LINK_STATUS_ACTIONS = ['PAID'];

    public function __construct(
        private readonly string $organizationId,
        private readonly string $eventJson,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        try {
            $event = json_decode($this->eventJson, true, 512, JSON_THROW_ON_ERROR);

            $linkStatus = $event['data']['link_status'] ?? null;

            if (! in_array($linkStatus, self::LINK_STATUS_ACTIONS, true)) {
                return $result;
            }

            $providerPaymentId = $event['data']['link_notes']['lago_invoice_id']
                ?? $event['data']['link_notes']['lago_payable_id']
                ?? null;

            if ($providerPaymentId === null) {
                return $result;
            }

            CashfreeService::updatePaymentStatus(
                organizationId: $this->organizationId,
                status: $linkStatus,
                cashfreePayment: new CashfreePayment(
                    id: (string) $providerPaymentId,
                    status: $linkStatus,
                    metadata: $this->symbolizeKeys($event['data']['link_notes'] ?? []),
                ),
                amountCents: self::linkAmountPaidCents($event),
            )->raiseIfError();

            return $result;
        } catch (FailedResult $e) {
            return $e->result;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /** Rails: link_amount_paid_cents — Money.from_amount(raw.to_d, currency).cents. */
    private static function linkAmountPaidCents(array $event): ?int
    {
        $raw = $event['data']['link_amount_paid'] ?? null;
        $currency = $event['data']['link_currency'] ?? null;

        if ($raw === null || $currency === null) {
            return null;
        }

        return CashfreeService::amountToCents($raw);
    }

    /**
     * Rails: event.dig("data", "link_notes").to_h.symbolize_keys — the
     * notes keys are symbolized for the metadata reads
     * (payment_type / lago_invoice_id).
     *
     * @param  array<string, mixed>  $notes
     * @return array<string, mixed>
     */
    private function symbolizeKeys(array $notes): array
    {
        return $notes;
    }
}
