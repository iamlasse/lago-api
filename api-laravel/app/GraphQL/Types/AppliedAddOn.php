<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\AppliedAddOn as AppliedAddOnModel;

/**
 * Field resolvers for the frozen SDL's `AppliedAddOn` type (port of Rails'
 * Types::AppliedAddOns::Object). Plain columns resolve through the
 * snake_case attribute fallback.
 */
class AppliedAddOn
{
    /** Rails: `belongs_to :add_on`. */
    public function addOn(AppliedAddOnModel $root): object
    {
        return $root->addOn;
    }
}
