<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Customer;

/**
 * Port of Rails' Invoices::IssuingDateService
 * (app/services/invoices/issuing_date_service.rb) — how many days the
 * issuing date shifts from the accounting date, given the grace period and
 * the anchor/adjustment settings.
 */
class IssuingDateService
{
    public function __construct(
        private readonly Customer $customerSettings,
        private readonly bool $recurring = false,
    ) {}

    public function issuingDateAdjustment(): int
    {
        if (! $this->recurring) {
            return $this->gracePeriod();
        }

        return match ($this->anchor().'_'.$this->adjustment()) {
            'current_period_end_keep_anchor' => -1,
            'current_period_end_align_with_finalization_date' => $this->gracePeriod() === 0 ? -1 : $this->gracePeriod(),
            'next_period_start_keep_anchor' => 0,
            'next_period_start_align_with_finalization_date' => $this->gracePeriod(),
            default => $this->gracePeriod(),
        };
    }

    public function gracePeriod(): int
    {
        return $this->customerSettings->applicableInvoiceGracePeriod();
    }

    private function anchor(): string
    {
        return $this->customerSettings->applicableSubscriptionInvoiceIssuingDateAnchor() ?? '';
    }

    private function adjustment(): string
    {
        return $this->customerSettings->applicableSubscriptionInvoiceIssuingDateAdjustment() ?? '';
    }
}
