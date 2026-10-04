<?php

declare(strict_types=1);

namespace App\Services\Orders\OneOff;

use App\Services\Orders\BaseExecuteService;
use App\Services\Invoices\CreateOneOffService;

/**
 * Port of Rails' Orders::OneOff::ExecuteService
 * (app/services/orders/one_off/execute_service.rb) — bills the order's
 * one-off add-on items on a one-off invoice for the customer.
 *
 * THE ORDER → INVOICE SEMANTICS: an execute_in_lago one-off order creates
 * (and finalizes) the invoice IMMEDIATELY — Invoices::CreateOneOffService
 * runs inside the execution transaction and the invoice id lands in the
 * execution record. An order_only execution records nothing but the
 * execution itself: no invoice is ever created. There is no
 * "invoiced on the next invoice" path for orders in this Rails surface.
 */
class ExecuteService extends BaseExecuteService
{
    protected function createRecords(): array
    {
        $invoice = $this->billOneOff();

        return ['invoice_id' => $invoice?->id];
    }

    private function billOneOff(): ?object
    {
        $order = $this->order;
        assert($order !== null);

        // Rails: billing_entity_id nil leaves CreateOneOffService falling
        // back to the customer's own entity, which is what a deal naming
        // none asks for.
        return CreateOneOffService::callBang(
            customer: $order->customer,
            currency: $order->currency(),
            fees: $this->buildFees(),
            timestamp: time(),
            billingEntityId: $order->quoteVersion()?->billing_entity_id,
            withDiscardedAddOns: true,
        )->invoice;
    }

    /**
     * Rails: `build_fees` — BOTH add-on identifiers are sent because the
     * billing services pick the one matching the source that triggered the
     * execution: the code under api, the id otherwise. An execution replays
     * the same snapshot whichever transport started it.
     *
     * @return list<array<string, mixed>>
     */
    private function buildFees(): array
    {
        $fees = [];

        foreach ($this->addOnItems() as $item) {
            $fees[] = [
                'add_on_id' => $item['id'] ?? null,
                'add_on_code' => $item['payload']['code'] ?? null,
                'units' => $this->effectiveValue($item, 'units'),
                'unit_amount_cents' => $this->effectiveValue($item, 'unitAmountCents'),
                'invoice_display_name' => $this->effectiveValue($item, 'invoiceDisplayName'),
                // Description may ride in either section (the payload is
                // free-form); overrides win. When neither carries one, the
                // fee falls back to the add-on description in
                // Fees::OneOffService.
                'description' => $this->effectiveValue($item, 'description'),
                'from_datetime' => $this->effectiveValue($item, 'fromDatetime'),
                'to_datetime' => $this->effectiveValue($item, 'toDatetime'),
            ];
        }

        return $fees;
    }

    /**
     * Rails: `add_on_items` — billing_items["addOns"] (the camelCase keys
     * are the frozen billing-items snapshot shape).
     *
     * @return list<array<string, mixed>>
     */
    private function addOnItems(): array
    {
        $items = $this->billingItems()['addOns'] ?? [];

        return array_values(is_array($items) ? $items : []);
    }
}
