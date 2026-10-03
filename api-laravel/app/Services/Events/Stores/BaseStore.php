<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

/**
 * Port of Rails' Events::Stores::BaseStore
 * (app/services/events/stores/base_store.rb) — the aggregation store
 * contract behind usage computation.
 *
 * SCOPE NOTE (events ingestion slice): this slice ports the STORE WIRING —
 * the factory decision and the Postgres/ClickHouse split. The aggregation
 * API itself (events/events_values/count/sum/max/last/unique_count/
 * weighted_sum and their grouped & prorated variants) is consumed by
 * Fees::ChargeService, the pay-in-advance aggregation services,
 * RealtimeUsage and UsageAttributions — none of which are ported yet — so
 * each aggregation entry point throws until the usage/metering slice lands
 * (Rails: NotImplementedError).
 */
abstract class BaseStore
{
    public function __construct(
        protected mixed $billingContext,
        protected array $boundaries = [],
        protected ?string $code = null,
        protected array $filters = [],
        protected bool $deduplicate = false,
    ) {}

    /** Rails: `precomputed?` — only the (unported) precomputed stores answer true. */
    public function precomputed(): bool
    {
        return false;
    }

    /** @throws LogicException until the usage/metering slice */
    public function events(bool $forceFrom = false, bool $ordered = false): never
    {
        $this->notPorted();
    }

    /** @throws LogicException until the usage/metering slice */
    public function eventsValues(?int $limit = null, bool $forceFrom = false): never
    {
        $this->notPorted();
    }

    /** @throws LogicException until the usage/metering slice */
    public function count(): never
    {
        $this->notPorted();
    }

    /** @throws LogicException until the usage/metering slice */
    public function sum(bool $withCount = true): never
    {
        $this->notPorted();
    }

    /** Rails: `boundaries[:from_datetime]&.to_time&.floor(3)` — floor to milliseconds. */
    protected function fromDatetime(): mixed
    {
        $fromDatetime = $this->boundaries['from_datetime'] ?? null;

        if ($fromDatetime === null) {
            return null;
        }

        $datetime = $fromDatetime->copy();
        $datetime->microsecond = (int) (floor($datetime->microsecond / 1000) * 1000);

        return $datetime;
    }

    /** Rails: `boundaries[:to_datetime]`. */
    protected function toDatetime(): mixed
    {
        return $this->boundaries['to_datetime'] ?? null;
    }

    /** Rails: `boundaries[:max_timestamp] || to_datetime`. */
    protected function applicableToDatetime(): mixed
    {
        return $this->boundaries['max_timestamp'] ?? $this->toDatetime();
    }

    // -- Aggregation API (TODO(port) with the usage/metering slice) -----------

    protected function notPorted(): never
    {
        // Rails: `raise NotImplementedError`.
        throw new \LogicException('Aggregation store API not ported yet (usage/metering slice) — TODO(port)');
    }
}
