<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract test for the invoice_graduated_percentage scenario, captured
 * from the real Rails API by scripts/contract/capture.sh
 * invoice_graduated_percentage.
 *
 * An arrears, calendar-monthly subscription whose only usage fee is a
 * graduated_percentage charge (ranges 0..10 flat 200 at 1%, 11..20 flat
 * 300 at 2%, 21..∞ flat 400 at 3%; one seeded May event of 15 → 50020c).
 * The golden was captured with the License premium flip described in the
 * scenario script (Rails gates the charge model itself; the fee pipeline
 * is the unmodified production path).
 * amount_details.graduated_percentage_ranges is the fidelity target. See
 * InvoiceChargeModelCase for the shared flow.
 */
class InvoiceGraduatedPercentageTest extends InvoiceChargeModelCase
{
    protected string $scenario = 'invoice_graduated_percentage';

    /** Must match invoice_graduated_percentage.rb's BILLING_AT. */
    protected function billingAt(): string
    {
        return '2025-06-01T00:00:00Z';
    }

    protected function customerExternalId(): string
    {
        return 'gradpct-customer-1';
    }

    protected function subscriptionExternalId(): string
    {
        return 'gradpct-sub-1';
    }
}
