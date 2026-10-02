<?php

declare(strict_types=1);

uses()->group('ledger:svc:ApiKeys.CacheService');

use App\Models\ApiKey;
use Illuminate\Support\Str;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use App\Services\ApiKeys\CacheService;

/**
 * Port of app/services/api_keys/cache_service.rb (no dedicated Rails spec
 * file for it; scenarios derived from the service itself and the
 * base_controller authentication scenarios that exercise it).
 */
function makeKeyedOrganization(array $apiKeyAttributes = []): array
{
    $organization = Organization::create(['name' => 'Cache Org']);
    $apiKey = ApiKey::create(array_merge([
        'organization_id' => $organization->id,
        'value' => (string) Str::uuid(),
        'permissions' => [],
    ], $apiKeyAttributes));

    return [$organization, $apiKey];
}

it('fetches the api key and organization from the database without cache', function (): void {
    [$organization, $apiKey] = makeKeyedOrganization();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('returns a nil pair for an unknown token', function (): void {
    expect(CacheService::call((string) Str::uuid()))->each(fn ($pair) => $pair)->toBeNull();
});

it('returns a nil pair for an expired api key (Rails default_scope :active)', function (): void {
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->subMinute()]);

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value);

    expect($foundKey)->toBeNull()
        ->and($foundOrganization)->toBeNull();
});

it('writes the pair to the cache on the first cached call', function (): void {
    [$organization, $apiKey] = makeKeyedOrganization();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id)
        ->and(Cache::get((new CacheService($apiKey->value))->cacheKey()))->toBeString();

    $payload = json_decode(Cache::get((new CacheService($apiKey->value))->cacheKey()), true);
    expect($payload['api_key']['value'])->toBe($apiKey->value)
        ->and($payload['organization']['id'])->toBe($organization->id);
});

it('serves the stale cached pair without hitting the database', function (): void {
    [$organization, $apiKey] = makeKeyedOrganization();

    CacheService::call($apiKey->value, withCache: true);

    // Change the database row — the cached copy is served untouched.
    $apiKey->update(['name' => 'renamed']);

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->name)->toBeNull()
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('serves a cached entry even when the database row has since expired', function (): void {
    // Rails: with_cache reads the cache and serves the pair while the cached
    // copy is not itself expired — DB-side expiry is invisible until the
    // cache TTL elapses.
    [$organization, $apiKey] = makeKeyedOrganization();

    CacheService::call($apiKey->value, withCache: true);

    // Cache holds the unexpired copy — Rails serves it regardless of the DB
    // row now expiring; the cache expires on its own TTL.
    $apiKey->forceFill(['expires_at' => now()->subMinute()])->save();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('rejects a cached entry whose api key has expired and refetches from the database', function (): void {
    // Happens when the key expires before the cache TTL elapses: the cached
    // payload's expires_at is already past (Rails: `unless api_key.expired?`),
    // so the pair is re-fetched — where the active scope hides an expired row.
    config(['lago.api_key_cache_ttl' => 3600]);
    [$organization, $apiKey] = makeKeyedOrganization();

    Cache::put(
        (new CacheService($apiKey->value))->cacheKey(),
        json_encode([
            'organization' => $organization->attributesToArray(),
            'api_key' => [
                'id' => $apiKey->id,
                'value' => $apiKey->value,
                'expires_at' => now()->subMinute()->format('Y-m-d H:i:s.u'),
                'permissions' => '{}',
            ],
        ]),
        3600,
    );

    // The database row is expired too — the active scope hides it.
    Illuminate\Support\Facades\DB::table('api_keys')
        ->where('id', $apiKey->id)
        ->update(['expires_at' => now()->subMinute()]);

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey)->toBeNull()
        ->and($foundOrganization)->toBeNull();
});

it('refetches a fresh pair when the cached entry is expired but the db row is valid', function (): void {
    config(['lago.api_key_cache_ttl' => 3600]);
    [$organization, $apiKey] = makeKeyedOrganization(['expires_at' => now()->addHour()]);

    // Seed an already-expired cached copy (the key expired between writes).
    Cache::put(
        (new CacheService($apiKey->value))->cacheKey(),
        json_encode([
            'organization' => $organization->attributesToArray(),
            'api_key' => [
                'id' => $apiKey->id,
                'value' => $apiKey->value,
                'expires_at' => now()->subMinute()->format('Y-m-d H:i:s.u'),
                'permissions' => '{}',
            ],
        ]),
        3600,
    );

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('expires the cache early when the api key expires sooner than the ttl', function (): void {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->addSeconds(120)]);

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBeLessThanOrEqual(120);
});

it('uses the configured ttl when the key outlives it', function (): void {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->addDay()]);

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBe(3600);
});

it('uses the configured ttl when the key never expires', function (): void {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization();

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBe(3600);
});

it('version-pins and token-keys the cache entry', function (): void {
    [, $apiKey] = makeKeyedOrganization();

    expect(CacheService::CACHE_KEY_VERSION)->toBe('1')
        ->and((new CacheService($apiKey->value))->cacheKey())->toBe('api_key/1/'.$apiKey->value);
});

it('expires all cache entries of an organization', function (): void {
    [$organization, $apiKey] = makeKeyedOrganization();
    $secondKey = ApiKey::create([
        'organization_id' => $organization->id,
        'value' => (string) Str::uuid(),
        'permissions' => [],
    ]);

    CacheService::call($apiKey->value, withCache: true);
    CacheService::call($secondKey->value, withCache: true);

    CacheService::expireAllCache($organization);

    expect(Cache::get((new CacheService($apiKey->value))->cacheKey()))->toBeNull()
        ->and(Cache::get((new CacheService($secondKey->value))->cacheKey()))->toBeNull();
});
