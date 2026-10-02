<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\ApiKey;

/**
 * Field resolvers for the frozen SDL's `SanitizedApiKey` type — port of
 * Rails' Types::ApiKeys::SanitizedObject (types/api_keys/sanitized_object.rb):
 * the `value` field returns the masked key `••••••••` + last 3 characters.
 */
class SanitizedApiKey
{
    /** Rails: "••••••••" + object.value.last(3). */
    public function value(ApiKey $root): string
    {
        return '••••••••'.mb_substr($root->value, -3);
    }
}
