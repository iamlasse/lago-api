<?php

uses()->group('ledger:svc:ApiKeys.CacheService');

use App\Models\ApiKey;
use App\Models\Organization;
use App\Services\ApiKeys\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

it('fetches the api key and organization from the database without cache', function () {
    [$organization, $apiKey] = makeKeyedOrganization();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('returns a nil pair for an unknown token', function () {
    expect(CacheService::call((string) Str::uuid()))->each(fn ($pair) => $pair)->toBeNull();
});

it('returns a nil pair for an expired api key (Rails default_scope :active)', function () {
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->subMinute()]);

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value);

    expect($foundKey)->toBeNull()
        ->and($foundOrganization)->toBeNull();
});

it('writes the pair to the cache on the first cached call', function () {
    [$organization, $apiKey] = makeKeyedOrganization();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->id)->toBe($apiKey->id)
        ->and($foundOrganization?->id)->toBe($organization->id)
        ->and(Cache::get((new CacheService($apiKey->value))->cacheKey()))->toBeString();

    $payload = json_decode(Cache::get((new CacheService($apiKey->value))->cacheKey()), true);
    expect($payload['api_key']['value'])->toBe($apiKey->value)
        ->and($payload['organization']['id'])->toBe($organization->id);
});

it('serves the stale cached pair without hitting the database', function () {
    [$organization, $apiKey] = makeKeyedOrganization();

    CacheService::call($apiKey->value, withCache: true);

    // Change the database row — the cached copy is served untouched.
    $apiKey->update(['name' => 'renamed']);

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey?->name)->toBeNull()
        ->and($foundOrganization?->id)->toBe($organization->id);
});

it('ignores a cached entry whose api key has expired and falls through to the database', function () {
    // Rails: with_cache reads the cache, rejects the entry when
    // api_key.expired?, then re-fetches from the database — where the active
    // scope hides the expired row, so the pair is nil.
    [$organization, $apiKey] = makeKeyedOrganization();

    CacheService::call($apiKey->value, withCache: true);

    $apiKey->forceFill(['expires_at' => now()->subMinute()])->save();

    [$foundKey, $foundOrganization] = CacheService::call($apiKey->value, withCache: true);

    expect($foundKey)->toBeNull()
        ->and($foundOrganization)->toBeNull();
});

it('expires the cache early when the api key expires sooner than the ttl', function () {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->addSeconds(120)]);

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBeLessThanOrEqual(120);
});

it('uses the configured ttl when the key outlives it', function () {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization(['expires_at' => now()->addDay()]);

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBe(3600);
});

it('uses the configured ttl when the key never expires', function () {
    config(['lago.api_key_cache_ttl' => 3600]);
    [, $apiKey] = makeKeyedOrganization();

    expect((new CacheService($apiKey->value))->cacheTtlFor($apiKey))->toBe(3600);
});

it('version-pins and token-keys the cache entry', function () {
    [, $apiKey] = makeKeyedOrganization();

    expect(CacheService::CACHE_KEY_VERSION)->toBe('1')
        ->and((new CacheService($apiKey->value))->cacheKey())->toBe('api_key/1/'.$apiKey->value);
});

it('expires all cache entries of an organization', function () {
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
