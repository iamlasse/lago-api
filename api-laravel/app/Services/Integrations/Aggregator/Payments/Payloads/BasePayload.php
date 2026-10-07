<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Payments\Payloads;

use App\Models\Payment;
use App\Models\Integration;
use App\Services\Integrations\Aggregator\BasePayload\Failure;
use App\Services\Integrations\Aggregator\BasePayload as AggregatorBasePayload;

/**
 * Port of Rails' Integrations::Aggregator::Payments::Payloads::BasePayload
 * (…/aggregator/payments/payloads/base_payload.rb) — the shared payment sync
 * shape (invoice id, account collection mapping, date, amount).
 */
abstract class BasePayload extends AggregatorBasePayload
{
    public function __construct(
        Integration $integration,
        public readonly Payment $payment,
    ) {
        // Rails: billing_entity: payment.payable.customer.billing_entity.
        parent::__construct($integration, $payment->payable?->customer?->billingEntity);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function body(): array
    {
        return [
            [
                'invoice_id' => $this->integration_invoice()->external_id,
                'account_code' => $this->account_item()?->external_account_code,
                'date' => \Illuminate\Support\Carbon::parse($this->payment->created_at)->toAtomString(),
                'amount_cents' => $this->payment->amount_cents,
            ],
        ];
    }

    /** Rails: `integration_payment` — the recorded IntegrationResource, if any. */
    public function integration_payment(): ?object
    {
        return \App\Models\IntegrationResource::query()
            ->where('integration_id', $this->integration->id)
            ->where('syncable_id', $this->payment->id)
            ->where('syncable_type', 'Payment')
            ->where('resource_type', \App\Models\IntegrationResource::RESOURCE_TYPE_PAYMENT)
            ->first();
    }

    /** Rails: `invoice` — the payment's payable. */
    protected function invoice(): ?object
    {
        return $this->payment->payable;
    }

    /**
     * Rails: `integration_invoice` — the invoice's recorded IntegrationResource
     * ("invoice_missing" failure when the invoice never synced).
     */
    protected function integration_invoice(): object
    {
        $invoice = $this->invoice();

        $integrationResource = $invoice === null
            ? null
            : \App\Models\IntegrationResource::query()
                ->where('integration_id', $this->integration->id)
                ->where('syncable_id', $invoice->id)
                ->where('syncable_type', 'Invoice')
                ->where('resource_type', \App\Models\IntegrationResource::RESOURCE_TYPE_INVOICE)
                ->first();

        if ($integrationResource === null) {
            throw new Failure('invoice_missing');
        }

        return $integrationResource;
    }

    /** Rails: `integration_customer` — the invoice customer's first accounting-kind row. */
    protected function integration_customer(): ?object
    {
        return $this->invoice()?->customer?->integrationCustomers()->accountingKind()->first();
    }
}
