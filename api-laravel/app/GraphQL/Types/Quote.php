<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Quote as QuoteModel;

/**
 * Field resolvers for the frozen SDL's `Quote` type (port of Rails'
 * Types::Quotes::Object). Plain columns resolve through the snake_case
 * attribute fallback; only the computed/attachment fields are listed here.
 */
class Quote
{
    /**
     * Rails: `images` — the attached image blobs. ActiveStorage attachment
     * lists are not ported for quotes yet (the addQuoteImage mutation ships
     * with them).
     *
     * TODO(port): has_many_attached :images.
     */
    public function images(QuoteModel $root): array
    {
        return [];
    }

    /** Rails: activity_logs — the ClickHouse activity log slice. */
    public function activityLogs(QuoteModel $root): ?array
    {
        return null;
    }

    /** Rails: order_forms — through the versions. */
    public function orderForms(QuoteModel $root): array
    {
        return $root->orderForms()->get()->all();
    }
}
