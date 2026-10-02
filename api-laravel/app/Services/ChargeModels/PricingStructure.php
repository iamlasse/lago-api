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
    ) {
        if ($this->currency === '') {
            throw new InvalidArgumentException('currency is mandatory');
        }
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
