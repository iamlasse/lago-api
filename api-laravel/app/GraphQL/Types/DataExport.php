<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `DataExport` type (port of Rails'
 * Types::DataExports::Object). `id` resolves through the attribute
 * fallback; only `status` is computed.
 */
class DataExport
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(\App\Models\DataExport $root): ?string
    {
        return $root->statusEnum()?->label();
    }
}
