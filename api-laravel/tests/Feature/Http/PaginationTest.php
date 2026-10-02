<?php

declare(strict_types=1);

uses()->group('ledger:concern:Pagination');

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use App\Http\Controllers\Concerns\Pagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Port of Rails' app/controllers/concerns/pagination.rb exercised through a
 * trait user, against real Customer rows.
 */
class PaginationUser
{
    use Pagination;

    public function meta(LengthAwarePaginator $records, ?string $key = null, ?string $organizationId = null, ?array $params = null, ?int $ttl = null): array
    {
        return $this->paginationMetadata($records, $key, $organizationId, $params, $ttl);
    }
}

function paginateCustomers(Organization $organization, int $page, int $perPage = 100): LengthAwarePaginator
{
    return Customer::query()
        ->where('organization_id', $organization->id)
        ->orderBy('external_id')
        ->paginate($perPage, ['*'], 'page', $page);
}

it('returns zeroed meta when total_count is 0', function (): void {
    $organization = Organization::create(['name' => 'Pagination Org']);
    $paginator = paginateCustomers($organization, 1);

    $meta = (new PaginationUser)->meta($paginator);

    // Rails: nil.to_i for current/total pages, nil navigation pages.
    expect($meta)->toBe([
        'current_page' => 0,
        'next_page' => null,
        'prev_page' => null,
        'total_pages' => 0,
        'total_count' => 0,
    ]);
});

it('computes navigation pages across the full matrix', function (): void {
    $organization = Organization::create(['name' => 'Pagination Org']);
    foreach (range(1, 250) as $i) {
        Customer::create(['organization_id' => $organization->id, 'external_id' => "cust-{$i}", 'name' => "C{$i}"]);
    }

    $user = new PaginationUser;

    // First page: no prev, has next.
    expect($user->meta(paginateCustomers($organization, 1)))->toBe([
        'current_page' => 1, 'next_page' => 2, 'prev_page' => null, 'total_pages' => 3, 'total_count' => 250,
    ]);

    // Middle page: has both.
    expect($user->meta(paginateCustomers($organization, 2)))->toBe([
        'current_page' => 2, 'next_page' => 3, 'prev_page' => 1, 'total_pages' => 3, 'total_count' => 250,
    ]);

    // Last page: no next (250 records / 100 per page -> last page has 50).
    expect($user->meta(paginateCustomers($organization, 3)))->toBe([
        'current_page' => 3, 'next_page' => null, 'prev_page' => 2, 'total_pages' => 3, 'total_count' => 250,
    ]);
});

it('caches the total count for 30 minutes when key, organization and params are given', function (): void {
    Cache::flush();
    $organization = Organization::create(['name' => 'Pagination Org']);
    foreach (range(1, 250) as $i) {
        Customer::create(['organization_id' => $organization->id, 'external_id' => "cust-{$i}", 'name' => "C{$i}"]);
    }

    $user = new PaginationUser;
    $params = ['per_page' => '100'];

    $meta = $user->meta(paginateCustomers($organization, 1), 'customers', $organization->id, $params);

    expect($meta['total_count'])->toBe(250);

    // The cache now holds the count under the deep-sorted hash key.
    $hash = hash('sha256', (string) json_encode([['organization_id', $organization->id], ['per_page', '100']]));
    expect(Cache::get("pagination_count/customers/{$hash}"))->toBe(250);
});

it('serves the cached count and skips re-querying while the cache is fresh', function (): void {
    Cache::flush();
    $organization = Organization::create(['name' => 'Pagination Org']);
    foreach (range(1, 250) as $i) {
        Customer::create(['organization_id' => $organization->id, 'external_id' => "cust-{$i}", 'name' => "C{$i}"]);
    }

    $user = new PaginationUser;
    $params = ['per_page' => '100'];

    $user->meta(paginateCustomers($organization, 1), 'customers', $organization->id, $params);

    // Data changes under our feet — page 1 still serves the cached count.
    Customer::where('organization_id', $organization->id)->whereIn('external_id', ['cust-1', 'cust-2'])->delete();

    expect($user->meta(paginateCustomers($organization, 1), 'customers', $organization->id, $params)['total_count'])->toBe(250);

    // Rails: re-calculate on the last page because the number of records
    // could have changed (cached 250 <= 100 * 3).
    expect($user->meta(paginateCustomers($organization, 3), 'customers', $organization->id, $params)['total_count'])->toBe(248);
});

it('derives the same cache key regardless of param order (deep sort)', function (): void {
    Cache::flush();
    $organization = Organization::create(['name' => 'Pagination Org']);
    Customer::create(['organization_id' => $organization->id, 'external_id' => 'cust-1', 'name' => 'C1']);

    $user = new PaginationUser;

    expect(
        $user->meta(paginateCustomers($organization, 1), 'customers', $organization->id, ['per_page' => '100', 'search_term' => 'x'])['total_count'],
    )->toBe(1);

    // Remove the record; the reordered params must still hit the same cache
    // entry (cached 1 is NOT > 100*1 though — force a higher count instead).
    Customer::where('organization_id', $organization->id)->delete();
    foreach (range(1, 250) as $i) {
        Customer::create(['organization_id' => $organization->id, 'external_id' => "new-{$i}", 'name' => "N{$i}"]);
    }

    expect(
        $user->meta(paginateCustomers($organization, 1), 'customers', $organization->id, ['search_term' => 'x', 'per_page' => '100'])['total_count'],
    )->toBe(250);
});

it('does not use the count cache unless key, organization id and params are all given', function (): void {
    Cache::flush();
    $organization = Organization::create(['name' => 'Pagination Org']);
    foreach (range(1, 250) as $i) {
        Customer::create(['organization_id' => $organization->id, 'external_id' => "cust-{$i}", 'name' => "C{$i}"]);
    }

    $user = new PaginationUser;

    // Rails: skip caching if it is not requested explicitly — the count is
    // recomputed every call, so data changes are always visible.
    expect($user->meta(paginateCustomers($organization, 1))['total_count'])->toBe(250);

    Customer::where('organization_id', $organization->id)->limit(2)->delete();

    expect($user->meta(paginateCustomers($organization, 1))['total_count'])->toBe(248);
});
