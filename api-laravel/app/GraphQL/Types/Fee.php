<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Fee as FeeModel;

/**
 * Field resolvers for the frozen SDL's `Fee` type (port of Rails'
 * Types::Fees::Object). Most fields resolve through the snake_case attribute
 * fallback / model relations; the two adjusted-fee fields are computed.
 */
class Fee
{
    /** Rails: `object.adjusted_fee.present?` — the fee carries an adjustment. */
    public function adjustedFee(FeeModel $root): bool
    {
        if ($root->relationLoaded('adjustedFee')) {
            return $root->adjustedFee !== null;
        }

        return $root->adjustedFee()->exists();
    }

    /** Rails: `adjusted_fee_type` — nil when only the display name was adjusted. */
    public function adjustedFeeType(FeeModel $root): ?string
    {
        $adjustedFee = $root->relationLoaded('adjustedFee')
            ? $root->adjustedFee
            : $root->adjustedFee()->first();

        if ($adjustedFee === null || $adjustedFee->adjustedDisplayName()) {
            return null;
        }

        return $adjustedFee->adjusted_units ? 'adjusted_units' : 'adjusted_amount';
    }
}
