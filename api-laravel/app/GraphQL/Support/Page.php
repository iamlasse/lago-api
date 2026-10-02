<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The wire shape of the frozen SDL's `*Collection` types
 * (`collection` + `metadata { currentPage, limitValue, totalPages, totalCount }`)
 * and the port of the kaminari semantics behind them
 * (app/queries/base_query.rb#paginate → `.page(page).per(limit)`).
 *
 * Kaminari defaults apply when the GraphQL arguments are absent: page 1,
 * limit 25 (`Kaminari.config.default_per_page`; Lago does not override it).
 * An out-of-range page yields an empty collection while the metadata keeps
 * the requested page and the real totals, exactly like kaminari.
 */
final class Page
{
    /** Kaminari's `default_per_page`. */
    public const DEFAULT_LIMIT = 25;

    /**
     * @param  iterable<mixed>  $collection
     * @param  object{currentPage: int, limitValue: int, totalPages: int, totalCount: int}  $metadata
     */
    public function __construct(
        public readonly iterable $collection,
        public readonly object $metadata,
    ) {}

    /**
     * Normalizes the wire `page`/`limit` pair the way kaminari does when a
     * resolver forwards nil (BaseQuery::DEFAULT_PAGINATION_PARAMS).
     *
     * @return array{int, int}
     */
    public static function normalizePageAndLimit(?int $page, ?int $limit): array
    {
        return [
            max(1, $page ?? 1),
            ($limit !== null && $limit >= 1) ? $limit : self::DEFAULT_LIMIT,
        ];
    }

    /** Builds the collection payload from a Laravel length-aware paginator. */
    public static function fromLengthAwarePaginator(LengthAwarePaginator $paginator): self
    {
        $limitValue = max(1, $paginator->perPage());
        $totalCount = $paginator->total();

        return new self(
            collection: $paginator->items(),
            metadata: (object) [
                'currentPage' => $paginator->currentPage(),
                'limitValue' => $limitValue,
                // Kaminari reports at least one page, even for an empty set.
                'totalPages' => max(1, (int) ceil($totalCount / $limitValue)),
                'totalCount' => $totalCount,
            ],
        );
    }
}
