<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Models\Charge;
use App\Enums\ChargeModel;
use App\Models\FixedCharge;
use NotImplementedException;
use InvalidArgumentException;

/**
 * Port of Rails' ChargeModels::PricingStructure
 * (app/services/charge_models/pricing_structure.rb) — a Data define carrying
 * everything the charge model services need to price units.
 */
final class PricingStructure
{
    public function __construct(
        public readonly ChargeModel $chargeModel,
        public readonly array $properties,
        public readonly bool $prorated,
        public readonly bool $acceptsTargetWallet,
        public readonly string $currency,
        /** Rails: `product_catalog` — a rate card's catalog product, not a plan charge. */
        public readonly bool $productCatalog = false,
    ) {
        if ($this->currency === '') {
            throw new InvalidArgumentException('currency is mandatory');
        }
    }

    /**
     * Port of Rails' `PricingStructure.from_billing_segment` — a stored
     * segment prices from its snapshotted rate, not a plan charge.
     */
    public static function fromBillingSegment(\App\Models\BillingSegment $billingSegment): self
    {
        $rateModel = ChargeModel::fromOption($billingSegment->rate()?->rate_model);

        if ($rateModel === null) {
            throw new NotImplementedException(
                'Rate model '.var_export($billingSegment->rate()?->rate_model, true).' is not implemented',
            );
        }

        return new self(
            chargeModel: ChargeModel::from($rateModel),
            properties: (array) $billingSegment->rate_properties,
            prorated: (bool) $billingSegment->contractRateCard->rateCard->proration(),
            acceptsTargetWallet: false,
            currency: (string) $billingSegment->currency,
            productCatalog: true,
        );
    }

    public static function fromCharge(Charge $charge): self
    {
        $chargeModel = ChargeModel::tryFrom((int) $charge->charge_model)
            ?? throw new NotImplementedException("Charge model {$charge->charge_model} is not implemented");

        return new self(
            chargeModel: $chargeModel,
            properties: $charge->properties ?? [],
            prorated: $charge->proratedCharge(),
            acceptsTargetWallet: (bool) $charge->accepts_target_wallet,
            currency: (string) $charge->plan->amount_currency,
        );
    }

    public static function fromFixedCharge(FixedCharge $fixedCharge): self
    {
        $chargeModel = ChargeModel::tryFrom((int) $fixedCharge->charge_model)
            ?? throw new NotImplementedException("Charge model {$fixedCharge->charge_model} is not implemented");

        return new self(
            chargeModel: $chargeModel,
            properties: $fixedCharge->properties ?? [],
            prorated: $fixedCharge->proratedCharge(),
            acceptsTargetWallet: false,
            currency: (string) $fixedCharge->plan->amount_currency,
        );
    }
}
