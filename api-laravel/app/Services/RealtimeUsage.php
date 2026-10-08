<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use App\Support\License;
use App\Models\Organization;
use App\Services\Events\Stores\StoreFactory;

/**
 * Port of the RealtimeUsage gate (app/services/realtime_usage.rb) — the
 * slice the wallet refresh triggers consumer needs: `enabled?` decides
 * whether an organization reads the realtime usage buckets, and so
 * whether its wallet refresh triggers are served inline or left to the
 * sweep.
 *
 * TODO(port): the rest of the module — SUPPORTED_AGGREGATION_TYPES /
 * SUPPORTED_CHARGE_MODELS / unsupported_reason (they gate the usage READ
 * path, which is the realtime-usage slice, not the consumers).
 */
final class RealtimeUsage
{
    /**
     * Port of `RealtimeUsage.enabled?(organization)` — premium only, only
     * when the ClickHouse events store is actually usable and selected for
     * the organization, then the env switch and the organization's
     * `realtime_usage` feature flag.
     */
    public static function enabled(Organization $organization): bool
    {
        if (! License::premium()) {
            return false;
        }

        if (self::storeOverrideActive()) {
            return false;
        }

        if (! StoreFactory::supportsClickhouse()) {
            return false;
        }

        if (! $organization->clickhouseEventsStore()) {
            return false;
        }

        if (! filter_var(config('lago.realtime_usage.enabled'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return in_array('realtime_usage', (array) $organization->feature_flags, true);
    }

    /**
     * Port of `StoreFactory.override`'s read — Rails reaches into
     * `Events::Stores::StoreFactory.override` (a public cattr_accessor);
     * the port keeps the state private (StoreFactory is a landed,
     * read-only slice file), so the check binds to the class scope.
     * TODO(port): expose a public `overrideActive()` reader on
     * StoreFactory and read it directly.
     */
    public static function storeOverrideActive(): bool
    {
        $reader = Closure::bind(fn (): ?array => StoreFactory::$override, null, StoreFactory::class);

        return $reader() !== null;
    }
}
