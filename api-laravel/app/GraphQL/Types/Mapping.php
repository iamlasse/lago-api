<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\IntegrationMappings\BaseMapping as BaseMappingModel;

/**
 * Field resolvers for the frozen SDL's `Mapping` type (port of Rails'
 * Types::IntegrationMappings::Object) — the settings accessors surface as
 * plain fields; `mappableType` and `billingEntityId` resolve through the
 * snake_case attribute fallback.
 */
class Mapping
{
    public function externalId(BaseMappingModel $root): ?string
    {
        return $root->getFromSettings('external_id');
    }

    public function externalAccountCode(BaseMappingModel $root): ?string
    {
        return $root->getFromSettings('external_account_code');
    }

    public function externalName(BaseMappingModel $root): ?string
    {
        return $root->getFromSettings('external_name');
    }
}
