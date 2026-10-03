<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Models\AddOn;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Support\Currency;
use App\Enums\FeeType;
use App\Services\BaseResult;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Fees::OneOffService (app/services/fees/one_off_service.rb)
 * — builds the add-on fees of a one-off invoice from the payload.
 *
 * The API context bills add-ons by code (`add_on_code`); internal callers
 * (graphql) bill by id (`add_on_id`).
 *
 * TODO(port): PricingUnit-priced add-ons (add_on.pricing_unit) are not
 * ported — amount math assumes fiat cents, like the Rails non-pricing-unit
 * path.
 */
class OneOffService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly array $fees,
        private readonly bool $withDiscardedAddOns = false,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('fees');

        $feesResult = $this->rescueFailures(function () use ($result): BaseResult {
            $result->fees = DB::transaction(fn (): array => $this->createFees());

            return $result;
        }, $result);

        return $feesResult;
    }

    /** @return list<Fee> */
    private function createFees(): array
    {
        $feesResult = [];

        foreach ($this->fees as $feeParams) {
            $addOn = $this->addOn(identifier: $feeParams['add_on_code'] ?? $feeParams['add_on_id'] ?? null);

            if ($addOn === null) {
                $result = BaseResult::of('fees');
                $result->notFoundFailure('add_on')->raiseIfError();
            }

            if (! $this->validBoundaries($feeParams)) {
                $result = BaseResult::of('fees');
                $result->singleValidationFailure('values_are_invalid', 'boundaries')->raiseIfError();
            }

            $unitAmountCents = $feeParams['unit_amount_cents'] ?? $addOn->amount_cents;
            $units = isset($feeParams['units']) ? (float) $feeParams['units'] : 1;
            $taxCodes = $feeParams['tax_codes'] ?? null;

            $fee = new Fee([
                'invoice_id' => $this->invoice->id,
                'organization_id' => $this->invoice->organization_id,
                'billing_entity_id' => $this->invoice->billing_entity_id,
                'add_on_id' => $addOn->id,
                'invoice_display_name' => $this->presence($feeParams['invoice_display_name'] ?? null),
                'description' => $feeParams['description'] ?? $addOn->description,
                'unit_amount_cents' => $unitAmountCents,
                'amount_cents' => MoneyMath::round(MoneyMath::mul((string) $unitAmountCents, (string) $units)),
                'precise_amount_cents' => MoneyMath::mul((string) $unitAmountCents, (string) $units),
                'amount_currency' => $this->invoice->currency,
                'fee_type' => FeeType::AddOn,
                'invoiceable_type' => 'AddOn',
                'invoiceable_id' => $addOn->id,
                'units' => (string) $units,
                'payment_status' => \App\Enums\FeePaymentStatus::Pending,
                'taxes_amount_cents' => 0,
                'taxes_precise_amount_cents' => '0',
                'properties' => [
                    'from_datetime' => $this->fromDatetime($feeParams)?->toIso8601String(),
                    'to_datetime' => $this->toDatetime($feeParams)?->toIso8601String(),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
            // Rails: fee.precise_unit_amount = fee.unit_amount.to_f (the
            // monetized Money wrapper — unit_amount_cents over the currency
            // subunit).
            $fee->precise_unit_amount = MoneyMath::fdiv(
                (string) $fee->unit_amount_cents,
                (string) Currency::subunitToUnit((string) $fee->amount_currency),
            );

            // Apply explicit payload taxes only when there is no tax provider.
            // Provider taxes take precedence and are handled async by ComputeTaxesAndTotalsService.
            // Explicit tax_codes must be applied here because they are ephemeral payload data.
            // Derived taxes (no tax_codes, no provider) are applied later by ComputeAmountsFromFees.
            if ($taxCodes !== null && $taxCodes !== [] && ! $this->customerProviderTaxation()) {
                ApplyTaxesService::callBang(fee: $fee, taxCodes: $taxCodes);
            }

            $fee->save();

            $feesResult[] = $fee;
        }

        return $feesResult;
    }

    private function addOn(mixed $identifier): ?AddOn
    {
        $finder = $this->apiContext() ? 'code' : 'id';

        $scope = $this->withDiscardedAddOns
            ? AddOn::withTrashed()->where('organization_id', $this->invoice->organization_id)
            : AddOn::query()->where('organization_id', $this->invoice->organization_id);

        return $scope->where($finder, $identifier)->first();
    }

    /**
     * TODO(port): provider taxation (customer.tax_customer) — the Anrok/
     * Avalara integrations are a later milestone; false keeps the local
     * taxes path.
     */
    private function customerProviderTaxation(): bool
    {
        return false;
    }

    /**
     * Rails: both boundaries present, valid ISO8601 and ordered.
     *
     * @param  array<string, mixed>  $feeParams
     */
    private function validBoundaries(array $feeParams): bool
    {
        $from = $feeParams['from_datetime'] ?? null;
        $to = $feeParams['to_datetime'] ?? null;

        if ($from === null && $to === null) {
            return true;
        }

        if ($from === null || $to === null) {
            return false;
        }

        if (! Datetime::validFormat($from) || ! Datetime::validFormat($to)) {
            return false;
        }

        return $this->fromDatetime($feeParams) <= $this->toDatetime($feeParams);
    }

    /**
     * @param  array<string, mixed>  $feeParams
     */
    private function fromDatetime(array $feeParams): ?\Carbon\CarbonInterface
    {
        $from = $feeParams['from_datetime'] ?? null;

        return $from === null ? now() : Datetime::parseIso8601($from);
    }

    /**
     * @param  array<string, mixed>  $feeParams
     */
    private function toDatetime(array $feeParams): ?\Carbon\CarbonInterface
    {
        $to = $feeParams['to_datetime'] ?? null;

        return $to === null ? now() : Datetime::parseIso8601($to);
    }

    /** Ruby `presence`. */
    private function presence(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
