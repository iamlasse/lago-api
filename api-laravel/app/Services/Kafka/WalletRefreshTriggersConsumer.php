<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use Throwable;
use RuntimeException;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use App\Models\Organization;
use App\Services\RealtimeUsage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Support\Metrics\RealtimeUsageMetrics;
use App\Services\Wallets\RealtimeRefreshService;

/**
 * Port of Rails' WalletRefreshTriggersConsumer
 * (app/consumers/wallet_refresh_triggers_consumer.rb) — consumes the
 * realtime usage triggers (upsert-format, keyed by
 * (organization_id, customer_id)) and refreshes the touched customers'
 * wallets inline rather than through a job, which keeps the partition's
 * ordering: the consumer never refreshes one customer twice at once.
 *
 * Laravel has no Karafka: the consumer logic lives here (one call per
 * topic batch, synthetic messages in the tests) and the transport is the
 * `kafka:consume` artisan command wired to php-rdkafka — see
 * app/Console/Commands/KafkaConsumeCommand.php.
 */
final class WalletRefreshTriggersConsumer
{
    /**
     * A batch outliving max.poll.interval.ms gets the member evicted and
     * the batch replayed by its next owner, forever. What the deadline
     * cuts stays flagged for the sweep.
     */
    public const CONSUME_DEADLINE_SECONDS = 120;

    public function __construct(private readonly ?RealtimeUsageMetrics $metrics = null) {}

    /**
     * Port of `#consume`.
     *
     * @param  list<KafkaMessage>  $messages
     */
    public function consume(array $messages): void
    {
        $tombstoneCount = 0;
        $payloads = [];

        foreach ($messages as $message) {
            // upsert-format retractions arrive as tombstones; non-JSON
            // payloads are dropped the same way.
            $payload = $message->decode();

            if ($payload === null) {
                $tombstoneCount++;

                continue;
            }

            $payloads[] = $payload;
        }

        $this->metrics()->message('trigger', count($payloads));
        $this->metrics()->message('tombstone', $tombstoneCount);

        $realtimeOrganizationIds = $this->realtimeOrganizationIds($payloads);
        $deadline = now()->addSeconds(self::CONSUME_DEADLINE_SECONDS);

        // group_by { |payload| [organization_id, customer_id] }
        $customerBatches = [];
        foreach ($payloads as $payload) {
            $organizationId = (string) ($payload['organization_id'] ?? '');
            $customerId = (string) ($payload['customer_id'] ?? '');
            $customerBatches[$organizationId.':'.$customerId] ??= [$organizationId, $customerId, []];
            $customerBatches[$organizationId.':'.$customerId][2][] = $payload;
        }

        foreach (array_values($customerBatches) as $index => [$organizationId, $customerId, $customerPayloads]) {
            if ($this->deadlineReached($deadline)) {
                // Rails: report(:skipped, :deadline, by: size - index) — the
                // customers behind the cut, current one included.
                $this->metrics()->outcome('skipped', 'deadline', count($customerBatches) - $index);

                break;
            }

            if (! $realtimeOrganizationIds->contains($organizationId)) {
                $this->metrics()->outcome('skipped', 'not_realtime');

                continue;
            }

            // The trigger sink emits for every metered customer, and most
            // hold no wallet.
            if (! Wallet::query()
                ->where('organization_id', $organizationId)
                ->where('customer_id', $customerId)
                ->active()
                ->exists()) {
                $this->metrics()->outcome('skipped', 'no_wallet');

                continue;
            }

            $this->refresh($organizationId, $customerId, $customerPayloads);
        }
    }

    private function metrics(): RealtimeUsageMetrics
    {
        return $this->metrics ?? app(RealtimeUsageMetrics::class);
    }

    // -- private ---------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $customerPayloads
     */
    private function refresh(string $organizationId, string $customerId, array $customerPayloads): void
    {
        $walletCodes = array_values(array_unique(array_filter(array_map(
            fn (array $p): ?string => (isset($p['target_wallet_code']) && is_scalar($p['target_wallet_code']) && (string) $p['target_wallet_code'] !== '')
                ? (string) $p['target_wallet_code']
                : null,
            $customerPayloads,
        ))));

        // Kept as integer epoch millis end-to-end: converting through
        // Time.at(float) can land a microsecond above the stored timestamp
        // and never match.
        $expectedIngestedAt = [];
        foreach ($customerPayloads as $payload) {
            $subscriptionId = $payload['subscription_id'] ?? null;
            $lastIngestedAt = $payload['last_ingested_at'] ?? null;

            if ($subscriptionId === null || $subscriptionId === '' || $lastIngestedAt === null) {
                continue;
            }

            $key = (string) $subscriptionId;
            $expectedIngestedAt[$key] = max($expectedIngestedAt[$key] ?? PHP_INT_MIN, (int) $lastIngestedAt);
        }

        try {
            $result = RealtimeRefreshService::call(
                organizationId: $organizationId,
                customerId: $customerId,
                walletCodes: $walletCodes,
                expectedIngestedAt: $expectedIngestedAt,
            );

            if (! $result->success()) {
                $this->metrics()->outcome('failed', 'refresh_failed');
                Log::error(sprintf(
                    '[wallets] realtime refresh failed customer_id=%s: %s',
                    $customerId,
                    $result->getError()?->getMessage(),
                ));
                // Rails: Sentry.capture_message
                report(new RuntimeException(sprintf(
                    'wallet realtime refresh failed customer_id=%s: %s',
                    $customerId,
                    $result->getError()?->getMessage(),
                )));

                return;
            }

            if ($result->reason !== null) {
                $this->metrics()->outcome('skipped', (string) $result->reason);

                return;
            }

            $this->metrics()->outcome('refreshed', 'none');
            $this->reportLatency($expectedIngestedAt);
        } catch (Throwable $exception) {
            // Raising out of #consume pauses the partition and replays the
            // batch, re-refreshing every customer already done.
            // StaleObjectError against the sweep is the expected one.
            $this->metrics()->outcome('failed', 'refresh_raised');
            Log::error(sprintf(
                '[wallets] realtime refresh raised customer_id=%s: %s %s',
                $customerId,
                $exception::class,
                $exception->getMessage(),
            ));
            // Rails: Sentry.capture_exception
            report($exception);
        }
    }

    /**
     * Port of `realtime_organization_ids` — off the realtime path no read
     * path uses the buckets, so the refresh would wait out its grace for
     * nothing and the sweep already covers those customers.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return Collection<int, string>
     */
    private function realtimeOrganizationIds(array $payloads): Collection
    {
        $organizationIds = array_values(array_unique(array_filter(array_map(
            fn (array $p): ?string => isset($p['organization_id']) && is_scalar($p['organization_id'])
                ? (string) $p['organization_id']
                : null,
            $payloads,
        ))));

        return Organization::query()
            ->whereIn('id', $organizationIds)
            ->get()
            ->filter(fn (Organization $organization): bool => RealtimeUsage::enabled($organization))
            ->map(fn (Organization $organization): string => (string) $organization->id)
            ->values();
    }

    private function deadlineReached(CarbonInterface $deadline): bool
    {
        if (! now()->gt($deadline)) {
            return false;
        }

        Log::warning('[wallets] realtime refresh batch hit its deadline, remaining customers left to the sweep');

        return true;
    }

    /**
     * @param  array<string, int>  $expectedIngestedAt
     */
    private function reportLatency(array $expectedIngestedAt): void
    {
        $watermarkMs = $expectedIngestedAt === [] ? null : max($expectedIngestedAt);

        if ($watermarkMs === null) {
            return;
        }

        $this->metrics()->latency(now()->getTimestampMs() / 1000.0 - $watermarkMs / 1000.0);
    }
}
