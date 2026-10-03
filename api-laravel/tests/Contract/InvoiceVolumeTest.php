<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract test for the invoice_volume scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_volume.
 *
 * An arrears, calendar-monthly subscription whose only usage fee is a
 * volume charge (ranges 0..100 @2+10, 101..200 @1+0, 201..∞ @0.5+50; three
 * seeded May events summing 250 units → third range → 17500c). The whole-
 * aggregation-in-one-range amount_details is the fidelity target. See
 * InvoiceChargeModelCase for the shared flow.
 */
class InvoiceVolumeTest extends InvoiceChargeModelCase
{
    protected string $scenario = 'invoice_volume';

    /** Must match invoice_volume.rb's BILLING_AT. */
    protected function billingAt(): string
    {
        return '2025-06-01T00:00:00Z';
    }

    protected function customerExternalId(): string
    {
        return 'volume-customer-1';
    }

    protected function subscriptionExternalId(): string
    {
        return 'volume-sub-1';
    }
}
