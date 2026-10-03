<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract test for the invoice_graduated scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_graduated.
 *
 * An arrears, calendar-monthly subscription whose only usage fee is a
 * graduated charge (ranges 0..10 @10+2, 11..20 @5+3, 21..∞ @5+3; three
 * seeded May events summing 21 units → 16300c). amount_details.
 * graduated_ranges (per-tier units, flat and per-unit amounts) is the
 * fidelity target. See InvoiceChargeModelCase for the shared flow.
 */
class InvoiceGraduatedTest extends InvoiceChargeModelCase
{
    protected string $scenario = 'invoice_graduated';

    /** Must match invoice_graduated.rb's BILLING_AT. */
    protected function billingAt(): string
    {
        return '2025-06-01T00:00:00Z';
    }

    protected function customerExternalId(): string
    {
        return 'graduated-customer-1';
    }

    protected function subscriptionExternalId(): string
    {
        return 'graduated-sub-1';
    }
}
