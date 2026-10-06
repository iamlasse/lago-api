<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Contract as ContractModel;

/**
 * Field resolvers for the frozen SDL's `Contract` type. The model casts its
 * PG enum columns to PHP backed enums; graphql-php would serialize the case
 * names (`Active`), while the wire carries the Rails enum values
 * (`active`) — map through `->value`.
 */
class Contract
{
    public function status(ContractModel $root): ?string
    {
        return $root->status?->value;
    }

    public function billingTime(ContractModel $root): ?string
    {
        return $root->billing_time?->value ?? $root->getRawOriginal('billing_time');
    }

    public function paymentMethodType(ContractModel $root): ?string
    {
        $raw = $root->getRawOriginal('payment_method_type');

        return $raw === null ? null : (string) $raw;
    }
}
