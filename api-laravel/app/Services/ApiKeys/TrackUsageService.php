<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Models\ApiKey;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\Cache;

/**
 * Port of Rails' ApiKeys::TrackUsageService
 * (app/services/api_keys/track_usage_service.rb): flushes the
 * `api_key_last_used_<id>` cache entries (written by the authenticated
 * request path) onto the api_keys rows, then deletes them. Runs hourly from
 * Clock::ApiKeys::TrackUsageJob.
 */
class TrackUsageService extends BaseService
{
    public const string CACHE_KEY_PREFIX = 'api_key_last_used_';

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        ApiKey::query()->each(function (ApiKey $apiKey): void {
            $cacheKey = self::CACHE_KEY_PREFIX.$apiKey->id;

            $lastUsedAt = Cache::get($cacheKey);

            if ($lastUsedAt === null) {
                return;
            }

            // Rails: update_columns (skips validations / updated_at).
            $apiKey->newQueryWithoutScopes()
                ->whereKey($apiKey->id)
                ->update(['last_used_at' => $lastUsedAt]);

            Cache::delete($cacheKey);
        });

        return $result;
    }
}
