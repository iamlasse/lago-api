<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\QuoteVersion as QuoteVersionModel;

/**
 * Field resolvers for the frozen SDL's `QuoteVersion` type (port of Rails'
 * Types::QuoteVersions::Object). Only the computed fields live here — the
 * plain columns (status, currency, billingItems, …) resolve through the
 * snake_case attribute fallback.
 */
class QuoteVersion
{
    /** Rails: `version` — the human-facing sequential id within the quote. */
    public function version(QuoteVersionModel $root): int
    {
        return $root->version();
    }
}
