<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::PaymentSerializer
 * (app/serializers/v1/payment_serializer.rb) — keys verbatim, including the
 * deprecated external_payment_id alias and the payable-polymorphic
 * invoice_ids / invoice_numbers.
 */
class PaymentSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var Payment $payment */
        $payment = $this->model;
        $payable = $payment->payable;

        return [
            'lago_id' => $payment->id,
            'lago_customer_id' => $payable?->customer?->id,
            'external_customer_id' => $payable?->customer?->external_id,
            'invoice_ids' => $this->invoiceIds($payable),
            'invoice_numbers' => $this->invoiceNumbers($payable),
            'lago_payable_id' => $payable?->id,
            'payable_type' => $payment->payable_type,
            'amount_cents' => $payment->amount_cents,
            'amount_currency' => $payment->amount_currency,
            'status' => $payment->status,
            'payment_status' => $payment->payablePaymentStatus(),
            'type' => $payment->payment_type,
            'reference' => $payment->reference,
            'payment_provider_code' => $payment->paymentProvider?->code,
            'payment_provider_type' => $payment->paymentProvider?->type,
            'external_payment_id' => $payment->provider_payment_id,
            'provider_payment_id' => $payment->provider_payment_id,
            'provider_customer_id' => $payment->paymentProviderCustomer?->provider_customer_id,
            'next_action' => $payment->provider_payment_data,
            'created_at' => $this->serializeDatetime($payment->created_at),
        ];

        // Note: payment_receipt / payment_method includes (Rails
        // include?(:payment_receipt) / include?(:payment_method)) are only
        // requested by the receipt slice — not emitted here yet.
    }

    /** @return list<string> */
    private function invoiceIds(?object $payable): array
    {
        if ($payable instanceof Invoice) {
            return [$payable->id];
        }

        if ($payable instanceof PaymentRequest) {
            return $payable->invoices->pluck('id')->all();
        }

        return [];
    }

    /** @return list<string> */
    private function invoiceNumbers(?object $payable): array
    {
        if ($payable instanceof Invoice) {
            return [(string) $payable->number];
        }

        if ($payable instanceof PaymentRequest) {
            return $payable->invoices->pluck('number')->map(fn ($n) => (string) $n)->all();
        }

        return [];
    }
}
