<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Tax as TaxModel;

/**
 * Field resolvers for the frozen SDL's `Tax` type. `appliedToOrganization`
 * shadows the model's `appliedToOrganization` query scope, which Eloquent's
 * attribute lookup would otherwise invoke as a bad accessor.
 */
class Tax
{
    public function appliedToOrganization(TaxModel $root): ?bool
    {
        return $root->applied_to_organization;
    }
}
