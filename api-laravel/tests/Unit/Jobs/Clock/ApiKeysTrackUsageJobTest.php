<?php

declare(strict_types=1);

use App\Models\ApiKey;
use Illuminate\Support\Facades\Cache;
use App\Jobs\Clock\ApiKeys\TrackUsageJob;
use App\Services\ApiKeys\TrackUsageService;

uses()->group('ledger:job:Clock.ApiKeys.TrackUsageJob');

/**
 * Port of Rails' spec/jobs/clock/api_keys/track_usage_job_spec.rb + the
 * TrackUsageService behaviour — the hourly flush of the
 * api_key_last_used_<id> cache entries onto the api_keys rows.
 */
it('flushes the cached last_used_at onto the api key', function (): void {
    $apiKey = ApiKey::factory()->create();

    Cache::forever(TrackUsageService::CACHE_KEY_PREFIX.$apiKey->id, '2026-10-01T12:00:00Z');

    (new TrackUsageJob)->handle();

    expect($apiKey->refresh()->last_used_at->toIso8601String())->toStartWith('2026-10-01T12:00:00')
        ->and(Cache::get(TrackUsageService::CACHE_KEY_PREFIX.$apiKey->id))->toBeNull();
});

it('leaves api keys without a cache entry untouched', function (): void {
    $apiKey = ApiKey::factory()->create(['last_used_at' => null]);

    (new TrackUsageJob)->handle();

    expect($apiKey->refresh()->last_used_at)->toBeNull();
});
