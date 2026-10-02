<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' app/controllers/concerns/pagination.rb.
 *
 * Pagination meta is rendered IN THE BODY (`meta` key), never as headers:
 * {current_page, next_page, prev_page, total_pages, total_count} — null
 * navigation pages and 0 current_page/total_pages when total_count is 0.
 * `page`/`per_page` default to 1/100. The total count is cached for 30
 * minutes, keyed by a sha256 of the deep-sorted query params (minus `page`)
 * plus the organization id; the cache is skipped (recomputed) when the
 * requested page is deep enough that the cached count may be stale or
 * useless (cached <= per_page * page).
 */
trait Pagination
{
    /** Default number of records per page. */
    protected const PER_PAGE = 100;

    /** The TTL (seconds) for caching the number of records. */
    protected const PAGINATION_COUNT_TTL = 1800;

    /**
     * @param  LengthAwarePaginator  $records  e.g. Model::paginate($perPage)
     * @param  string|null  $key  logical name for the count cache bucket
     *                         (e.g. "customers") — caching is only enabled
     *                         when key, organizationId and params are given
     * @param  array<string, mixed>|null  $params  the request's query params
     * @return array{current_page: int, next_page: int|null, prev_page: int|null, total_pages: int, total_count: int}
     */
    protected function paginationMetadata(
        LengthAwarePaginator $records,
        ?string $key = null,
        ?string $organizationId = null,
        ?array $params = null,
        ?int $ttl = null,
    ): array {
        $totalCount = $this->countTotal($records, $key, $organizationId, $params, $ttl);

        $currentPage = $nextPage = $prevPage = $totalPages = null;

        if ($totalCount > 0) {
            $currentPage = $records->currentPage();
            $totalPages = (int) ceil($totalCount / max($records->perPage(), 1));
            $nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;
            $prevPage = $currentPage > 1 ? $currentPage - 1 : null;
        }

        return [
            'current_page' => (int) ($currentPage ?? 0),
            'next_page' => $nextPage,
            'prev_page' => $prevPage,
            'total_pages' => (int) ($totalPages ?? 0),
            'total_count' => $totalCount,
        ];
    }

    /**
     * Port of Pagination#_count_total: bypasses the cache unless it was
     * requested explicitly (backward-compatible Rails behavior).
     */
    private function countTotal(
        LengthAwarePaginator $records,
        ?string $key,
        ?string $organizationId,
        ?array $params,
        ?int $ttl,
    ): int {
        if ($key === null || $organizationId === null || $params === null) {
            return $records->total();
        }

        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? self::PER_PAGE);

        $hashData = $this->deepSort(array_merge(
            Arr::except($params, 'page'),
            ['organization_id' => $organizationId],
        ));
        $hash = hash('sha256', (string) json_encode($hashData));
        $cacheKey = "pagination_count/{$key}/{$hash}";

        // Re-calculate on the last page because the number of records could
        // have changed. If the count is small (less than the page size),
        // caching is useless but re-querying is acceptable.
        $cached = Cache::get($cacheKey);
        if ((int) ($cached ?? 0) > $perPage * $page) {
            return (int) $cached;
        }

        $count = $records->total();
        Cache::put($cacheKey, $count, $ttl ?? self::PAGINATION_COUNT_TTL);

        return $count;
    }

    /**
     * Port of Pagination#_deep_sort: recursively converts hashes into sorted
     * arrays of [key, value] pairs so the cache key is deterministic
     * regardless of param order.
     */
    private function deepSort(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map($this->deepSort(...), $value);
        }

        if (is_array($value)) {
            $pairs = [];
            foreach ($value as $k => $v) {
                $pairs[] = [(string) $k, $this->deepSort($v)];
            }
            usort($pairs, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

            return $pairs;
        }

        return $value;
    }
}
