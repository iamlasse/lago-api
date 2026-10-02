<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Plan;
use App\Models\Customer;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\FeeAppliedTax;
use Illuminate\Support\Collection;

/**
 * Port of Rails' Fees::ApplyTaxesService
 * (app/services/fees/apply_taxes_service.rb) — builds the per-fee snapshot
 * rows in `fees_taxes` walking the tax chain: resource → plan → customer →
 * billing entity defaults.
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Fee $fee,
        private readonly ?array $taxCodes = null,
        private readonly ?Customer $customer = null,
        private readonly ?Plan $plan = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('applied_taxes');
        $result->applied_taxes = [];

        if ($this->fee->appliedTaxes()->exists()) {
            return $result;
        }

        $appliedTaxesAmountCents = 0;
        $appliedPreciseTaxesAmountCents = '0';
        $appliedTaxesRate = 0.0;

        foreach ($this->applicableTaxes() as $tax) {
            $appliedTax = new FeeAppliedTax([
                'organization_id' => $this->fee->organization_id,
                'tax_id' => $tax->id,
                'tax_description' => $tax->description,
                'tax_code' => $tax->code,
                'tax_name' => $tax->name,
                'tax_rate' => $tax->rate,
                'amount_currency' => $this->fee->amount_currency,
            ]);
            $appliedTax->fee_id = $this->fee->id;

            $this->fee->appliedTaxes->add($appliedTax);

            // Rails: (sub_total * tax.rate).fdiv(100) — float math, rounded
            // half-away-from-zero; precise variant stays in bcmath.
            $subTotal = $this->fee->subTotalExcludingTaxesAmountCents();
            $taxAmountCents = (float) $subTotal * (float) $tax->rate / 100;
            $taxPreciseAmountCents = MoneyMath::fdiv(
                MoneyMath::mul($this->fee->subTotalExcludingTaxesPreciseAmountCents(), (string) $tax->rate),
                '100',
            );

            $appliedTax->amount_cents = MoneyMath::round((string) $taxAmountCents);
            $appliedTax->precise_amount_cents = $taxPreciseAmountCents;

            if ($this->fee->exists) {
                $appliedTax->save();
            }

            $appliedTaxesAmountCents += $taxAmountCents;
            $appliedPreciseTaxesAmountCents = MoneyMath::add($appliedPreciseTaxesAmountCents, $taxPreciseAmountCents);
            $appliedTaxesRate += (float) $tax->rate;

            $result->applied_taxes[] = $appliedTax;
        }

        $this->fee->taxes_amount_cents = MoneyMath::round((string) $appliedTaxesAmountCents);
        $this->fee->taxes_precise_amount_cents = $appliedPreciseTaxesAmountCents;
        $this->fee->taxes_rate = $appliedTaxesRate;

        return $result;
    }

    /**
     * Port of `applicable_taxes` — the tax chain, first match wins.
     *
     * @return Collection<int, Tax>
     */
    private function applicableTaxes(): Collection
    {
        // organization.taxes — all taxes created on the organization
        if ($this->taxCodes !== null) {
            return $this->resolveCustomer()->organization->taxes()
                ->whereIn('code', $this->taxCodes)
                ->get();
        }

        $fee = $this->fee;

        if ($fee->typeEnum() === \App\Enums\FeeType::AddOn
            && $fee->addOn !== null
            && $fee->addOn->taxes()->exists()) {
            return $fee->addOn->taxes()->get();
        }

        if ($fee->isCharge() && $fee->charge !== null && $fee->charge->taxes()->exists()) {
            return $fee->charge->taxes()->get();
        }

        if ($fee->fixedCharge !== null && $fee->typeEnum() === \App\Enums\FeeType::FixedCharge
            && $fee->fixedCharge->taxes()->exists()) {
            return $fee->fixedCharge->taxes()->get();
        }

        $plan = $this->resolvePlan();

        if (in_array($fee->typeEnum(), [\App\Enums\FeeType::Charge, \App\Enums\FeeType::Subscription, \App\Enums\FeeType::Commitment, \App\Enums\FeeType::FixedCharge], true)
            && $plan !== null
            && $plan->taxes()->exists()) {
            return $plan->taxes()->get();
        }

        $customer = $this->resolveCustomer();

        if ($customer->taxes()->exists()) {
            return $customer->taxes()->get();
        }

        // billing_entity.taxes — the default taxes applied on the billing entity
        return Tax::query()
            ->join('billing_entities_taxes', 'billing_entities_taxes.tax_id', '=', 'taxes.id')
            ->where('billing_entities_taxes.billing_entity_id', $customer->billing_entity_id)
            ->get();
    }

    private function resolveCustomer(): Customer
    {
        return $this->customer
            ?? $this->fee->invoice?->customer
            ?? $this->fee->subscription->customer;
    }

    private function resolvePlan(): ?Plan
    {
        return $this->plan ?? $this->fee->subscription?->plan;
    }
}
