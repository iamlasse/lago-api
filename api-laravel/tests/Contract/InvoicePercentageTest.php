<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract test for the invoice_percentage scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_percentage.
 *
 * An arrears, calendar-monthly subscription whose only usage fee is a
 * percentage charge (rate 1.3%, fixed_amount 2.0, free_units_per_events 3,
 * free_units_per_total_aggregation 250; four seeded May events of 200 →
 * sum 800, count 4 → 1315c). amount_details' free/paid units AND events
 * split is the fidelity target — and the deepest reach of the aggregation
 * seam, which cannot carry the event count or running_total (see
 * InvoiceChargeModelCase). See InvoiceChargeModelCase for the shared flow.
 */
class InvoicePercentageTest extends InvoiceChargeModelCase
{
    protected string $scenario = 'invoice_percentage';

    /** Must match invoice_percentage.rb's BILLING_AT. */
    protected function billingAt(): string
    {
        return '2025-06-01T00:00:00Z';
    }

    protected function customerExternalId(): string
    {
        return 'percentage-customer-1';
    }

    protected function subscriptionExternalId(): string
    {
        return 'percentage-sub-1';
    }
}
