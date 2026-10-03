<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `InvoiceCollectionMetadata` type —
 * the port of Rails' BaseQuery::CappedTotalCount module, which the
 * `invoices` resolver extends onto the paginated relation:
 *
 * - `totalCount` is capped at MAX_COUNTED_RECORDS (10 000) and becomes a
 *   lower bound beyond it;
 * - `totalCountCapped` flags the cap;
 * - `hasNextPage` stays exact even when the total is capped (`last_page?`
 *   semantics — an out-of-range page reports no next page).
 *
 * The paginator behind the port counts every matching row (no
 * `without_count` optimization), so the cap only ever engages on result
 * sets larger than the limit, exactly like Rails.
 */
class InvoiceCollectionMetadata
{
    /** Rails: BaseQuery::CappedTotalCount::MAX_COUNTED_RECORDS. */
    private const MAX_COUNTED_RECORDS = 10_000;

    public function totalCount(object $metadata): int
    {
        return min((int) ($metadata->totalCount ?? 0), self::MAX_COUNTED_RECORDS);
    }

    public function totalCountCapped(object $metadata): bool
    {
        return (int) ($metadata->totalCount ?? 0) > self::MAX_COUNTED_RECORDS;
    }

    /** Rails: !out_of_range? && !last_page? — currentPage < totalPages covers both. */
    public function hasNextPage(object $metadata): bool
    {
        return (int) ($metadata->currentPage ?? 0) < (int) ($metadata->totalPages ?? 0);
    }
}
