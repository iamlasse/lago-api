<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Models\BaseModel;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' app/services/api_keys/cache_service.rb: caches the
 * ApiKey + Organization pair (JSON of raw column values) under
 * `api_key/<version>/<token>` with the configured TTL — shortened when the
 * key itself expires sooner. Expired keys are never served from cache; a
 * lookup falls through to the database, where the Rails `default_scope
 * :active` on ApiKey is replicated (expired rows read as missing).
 */
class CacheService
{
    public const CACHE_KEY_VERSION = '1';

    public function __construct(
        private readonly string $authToken,
        private readonly bool $withCache = false,
    ) {}

    /**
     * Port of BaseService's `self.call` -> instance `call`.
     *
     * @return array{0: ApiKey|null, 1: Organization|null}
     */
    public static function call(string $authToken, bool $withCache = false): array
    {
        return (new self($authToken, $withCache))->execute();
    }

    public static function expireAllCache(Organization $organization): void
    {
        foreach ($organization->apiKeys as $apiKey) {
            self::expireCache($apiKey->value);
        }
    }

    public static function expireCache(string $authToken): void
    {
        Cache::delete((new self($authToken))->cacheKey());
    }

    /** @return array{0: ApiKey|null, 1: Organization|null} */
    public function execute(): array
    {
        // When no cache, just return the values from the database.
        if (! $this->withCache) {
            return $this->fetchFromDatabase();
        }

        $cached = Cache::get($this->cacheKey());
        if (is_string($cached)) {
            $payload = json_decode($cached, true);
            $apiKey = $this->instantiate(ApiKey::class, is_array($payload['api_key'] ?? null) ? $payload['api_key'] : []);

            // Avoid returning an expired API key.
            if ($apiKey !== null && ! $this->isExpired($apiKey)) {
                $organization = $this->instantiate(Organization::class, is_array($payload['organization'] ?? null) ? $payload['organization'] : []);

                return [$apiKey, $organization];
            }
        }

        // In last resort, fetch from the database and write to the cache.
        [$apiKey, $organization] = $this->fetchFromDatabase();

        if ($apiKey !== null) {
            $this->writeToCache($apiKey, $organization);
        }

        return [$apiKey, $organization];
    }

    public function cacheKey(): string
    {
        return implode('/', array_filter([
            'api_key',
            self::CACHE_KEY_VERSION,
            $this->authToken,
        ]));
    }

    /**
     * Seconds the cache entry should live: the configured TTL, unless the
     * key expires sooner (port of CacheService#write_to_cache).
     */
    public function cacheTtlFor(ApiKey $apiKey): int
    {
        $cacheDuration = (int) config('lago.api_key_cache_ttl');
        $expiresAt = $apiKey->expires_at;

        if ($expiresAt !== null && $expiresAt->timestamp < now()->timestamp + $cacheDuration) {
            return (int) ($expiresAt->timestamp - now()->timestamp);
        }

        return $cacheDuration;
    }

    /**
     * @return array{0: ApiKey|null, 1: Organization|null}
     */
    private function fetchFromDatabase(): array
    {
        // Rails: ApiKey.includes(:organization).find_by(value:) — ApiKey has
        // default_scope { active }, i.e. expired keys are invisible.
        $apiKey = ApiKey::query()
            ->where('value', $this->authToken)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        return [$apiKey, $apiKey?->organization];
    }

    private function writeToCache(ApiKey $apiKey, ?Organization $organization): void
    {
        // Ensure cache is kept for 1 hour at most (10 seconds in development).
        Cache::put(
            $this->cacheKey(),
            json_encode([
                'organization' => $organization?->attributesToArray() ?? [],
                'api_key' => $apiKey->attributesToArray(),
            ], JSON_THROW_ON_ERROR),
            $this->cacheTtlFor($apiKey),
        );
    }

    /**
     * Port of ActiveRecord's `.instantiate`: hydrate raw column values
     * without touching the database. Values decoded from the cached JSON
     * are already "parsed" (jsonb columns arrive as arrays), so they are
     * re-encoded where a cast expects the raw string form.
     *
     * @param  class-string  $class
     */
    private function instantiate(string $class, array $attributes): ?object
    {
        if ($attributes === []) {
            return null;
        }

        /** @var BaseModel $model */
        $model = new $class;

        foreach ($model->getCasts() as $column => $cast) {
            if (($cast === 'array' || str_starts_with($cast, 'array:')) && isset($attributes[$column]) && is_array($attributes[$column])) {
                $attributes[$column] = json_encode($attributes[$column], JSON_THROW_ON_ERROR);
            }
        }

        $model->setRawAttributes($attributes, true);

        return $model;
    }

    /**
     * Port of ApiKey#expired?.
     */
    private function isExpired(ApiKey $apiKey): bool
    {
        return $apiKey->expires_at !== null && $apiKey->expires_at->timestamp < now()->timestamp;
    }
}
