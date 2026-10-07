<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Payments\Payloads;

/**
 * Port of Rails' Integrations::Aggregator::Payments::Payloads::Netsuite
 * (…/aggregator/payments/payloads/netsuite.rb) — the customerpayment
 * restlet shape applying the payment to the synced invoice.
 */
final class Netsuite extends BasePayload
{
    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $invoice = $this->invoice();

        $paymentAmount = $this->amount($this->payment->amount_cents, resource: $invoice);

        return [
            'isDynamic' => true,
            'columns' => [
                'customer' => $this->integration_customer()?->external_customer_id,
                'payment' => $paymentAmount,
            ],
            'options' => [
                'ignoreMandatoryFields' => false,
            ],
            'type' => 'customerpayment',
            'lines' => [
                [
                    'lineItems' => [
                        [
                            'amount' => $paymentAmount,
                            'apply' => true,
                            'doc' => $this->integration_invoice()->external_id,
                        ],
                    ],
                    'sublistId' => 'apply',
                ],
            ],
        ];
    }
}
