<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Contract test for the invoice_package scenario, captured from the real
 * Rails API by scripts/contract/capture.sh invoice_package.
 *
 * An arrears, calendar-monthly subscription whose only usage fee is a
 * package charge (amount 100, package_size 10, free_units 10; three seeded
 * May events summing 121 units → 111 paid → 12 packages → 120000c).
 * amount_details.{free_units,paid_units,per_package_size} is the fidelity
 * target. See InvoiceChargeModelCase for the shared flow.
 */
class InvoicePackageTest extends InvoiceChargeModelCase
{
    protected string $scenario = 'invoice_package';

    /** Must match invoice_package.rb's BILLING_AT. */
    protected function billingAt(): string
    {
        return '2025-06-01T00:00:00Z';
    }

    protected function customerExternalId(): string
    {
        return 'package-customer-1';
    }

    protected function subscriptionExternalId(): string
    {
        return 'package-sub-1';
    }
}
