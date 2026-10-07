<?php

declare(strict_types=1);

namespace App\Services\DataExports\Csv;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use App\Models\InvoiceSubscription;

/**
 * Port of Rails' DataExports::Csv::ResolveFeeBillingPeriodService
 * (app/services/data_exports/csv/resolve_fee_billing_period_service.rb):
 * resolves the billing period (from_datetime / to_datetime) a fee was
 * charged over, from the fee's properties or the invoice's subscription
 * boundaries.
 */
class ResolveFeeBillingPeriodService extends BaseService
{
    public function __construct(
        private readonly Fee $fee,
        private readonly ?InvoiceSubscription $invoiceSubscription,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('from_datetime', 'to_datetime');

        [$fromDatetime, $toDatetime] = $this->billingPeriod();

        $result->from_datetime = $this->parseDatetime($fromDatetime);
        $result->to_datetime = $this->parseDatetime($toDatetime);

        return $result;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function billingPeriod(): array
    {
        $feeType = $this->fee->typeEnum();

        return match ($feeType) {
            FeeType::Subscription => $this->propertiesPeriod('from_datetime', 'to_datetime')
                ?? $this->subscriptionPeriod(),
            FeeType::FixedCharge => $this->propertiesPeriod('fixed_charges_from_datetime', 'fixed_charges_to_datetime')
                ?? $this->fixedChargePeriod(),
            FeeType::Commitment => $this->propertiesPeriod('from_datetime', 'to_datetime')
                ?? $this->commitmentPeriod(),
            FeeType::Charge => $this->chargePeriod(),
            FeeType::AddOn => $this->propertiesPeriod('from_datetime', 'to_datetime'),
            default => $this->chargesPeriod(),
        };
    }

    /** @return array{0: mixed, 1: mixed}|null */
    private function propertiesPeriod(string $fromKey, string $toKey): ?array
    {
        $fromDatetime = $this->fee->properties[$fromKey] ?? null;
        $toDatetime = $this->fee->properties[$toKey] ?? null;

        // Rails: present? on both.
        if ($this->present($fromDatetime) && $this->present($toDatetime)) {
            return [$fromDatetime, $toDatetime];
        }

        return null;
    }

    /** @return array{0: mixed, 1: mixed} */
    private function subscriptionPeriod(): array
    {
        return [$this->invoiceSubscription->from_datetime, $this->invoiceSubscription->to_datetime];
    }

    /** @return array{0: mixed, 1: mixed} */
    private function fixedChargePeriod(): array
    {
        return [
            $this->invoiceSubscription->fixed_charges_from_datetime,
            $this->invoiceSubscription->fixed_charges_to_datetime,
        ];
    }

    /** @return array{0: mixed, 1: mixed} */
    private function commitmentPeriod(): array
    {
        if (! $this->invoiceSubscription->subscription->plan->pay_in_advance) {
            return $this->subscriptionPeriod();
        }

        $previousInvoiceSubscription = $this->invoiceSubscription->previousInvoiceSubscription();

        if ($previousInvoiceSubscription !== null) {
            return [
                $previousInvoiceSubscription->from_datetime,
                $previousInvoiceSubscription->to_datetime,
            ];
        }

        return [null, null];
    }

    /** @return array{0: mixed, 1: mixed} */
    private function chargePeriod(): array
    {
        if ($this->fee->pay_in_advance) {
            return $this->propertiesPeriod('charges_from_datetime', 'charges_to_datetime')
                ?? $this->chargesPeriod();
        }

        return $this->chargesPeriod();
    }

    /** @return array{0: mixed, 1: mixed} */
    private function chargesPeriod(): array
    {
        return [
            $this->invoiceSubscription->charges_from_datetime,
            $this->invoiceSubscription->charges_to_datetime,
        ];
    }

    private function parseDatetime(mixed $value): ?Carbon
    {
        if (is_string($value)) {
            return \Illuminate\Support\Facades\Date::parse($value);
        }

        return $value;
    }

    /** Ruby `present?`. */
    private function present(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }
}
