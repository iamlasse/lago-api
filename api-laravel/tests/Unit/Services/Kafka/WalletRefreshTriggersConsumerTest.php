<?php

declare(strict_types=1);

uses()->group(
    'ledger:consumer:WalletRefreshTriggersConsumer',
    'ledger:svc:Wallets.RealtimeRefreshService',
);

use App\Models\Wallet;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use App\Services\Kafka\KafkaMessage;
use App\Support\Metrics\MetricsSink;
use App\Support\Metrics\RealtimeUsageMetrics;
use App\Services\Kafka\WalletRefreshTriggersConsumer;
use App\Services\Wallets\Buckets\UsageBucketReadiness;

/**
 * Port of Rails' spec/consumers/wallet_refresh_triggers_consumer_spec.rb —
 * the realtime wallet refresh path: trigger payloads collapse to one
 * refresh per customer at the latest watermark, tombstones count apart,
 * non-realtime and wallet-less customers are walked past, and a raising
 * refresh never takes down the batch. The Yabeda counters port to the
 * MetricsSink seam (spy below).
 */

/**
 * The Yabeda spy — records the increments/measures the consumer reports.
 */
final class TriggerConsumerMetricsSpy implements MetricsSink
{
    /** @var list<array{metric: string, labels: array<string, mixed>, by: float|int}> */
    public array $increments = [];

    /** @var list<array{metric: string, labels: array<string, mixed>, value: float}> */
    public array $measures = [];

    public function increment(string $metric, array $labels = [], int $by = 1): void
    {
        $this->increments[] = ['metric' => $metric, 'labels' => $labels, 'by' => $by];
    }

    public function measure(string $metric, array $labels = [], float $value = 0.0): void
    {
        $this->measures[] = ['metric' => $metric, 'labels' => $labels, 'value' => $value];
    }

    public function outcomeCalls(string $outcome, string $reason): int
    {
        return count(array_filter($this->increments, fn (array $i): bool => $i['metric'] === 'wallet_refresh_outcomes_total'
            && $i['labels']['outcome'] === $outcome
            && $i['labels']['reason'] === $reason));
    }

    public function messageCalls(string $kind): int
    {
        return array_sum(array_map(fn (array $i): int => $i['metric'] === 'wallet_refresh_messages_total' && $i['labels']['kind'] === $kind ? (int) $i['by'] : 0, $this->increments));
    }
}

function triggerConsumerEnvironment(): array
{
    $organization = Organization::factory()->create([
        'clickhouse_events_store' => true,
        'feature_flags' => ['realtime_usage'],
    ]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->for($customer)->create(['organization_id' => $organization->id]);
    Wallet::factory()->forCustomer($customer)->create(['rate_amount' => '1']);

    // RealtimeUsage.enabled? gate: premium + clickhouse usable + env switch.
    config([
        'lago.license' => 'premium-license-token',
        'lago.clickhouse.enabled' => true,
        'lago.realtime_usage.enabled' => true,
    ]);

    return [$organization, $customer, $subscription];
}

function triggerMessage(array $payload): KafkaMessage
{
    return new KafkaMessage('wallet_refresh_triggers', json_encode($payload, JSON_THROW_ON_ERROR));
}

function triggerConsumer(TriggerConsumerMetricsSpy $spy): WalletRefreshTriggersConsumer
{
    app()->instance(RealtimeUsageMetrics::class, new RealtimeUsageMetrics($spy));

    return new WalletRefreshTriggersConsumer;
}

it('refreshes the customer wallets', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();
    $watermarkMs = (int) (now()->getTimestampMs());
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'last_ingested_at' => $watermarkMs,
            'target_wallet_code' => null,
        ]),
    ]);

    expect($spy->outcomeCalls('refreshed', 'none'))->toBe(1)
        // the sweep flag the refresh clears
        ->and($customer->fresh()->awaiting_wallet_refresh)->toBeFalse()
        ->and($customer->wallets()->first()->fresh()->ongoing_usage_balance_cents)->toBe(0);
});

it('counts the messages it consumed', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->messageCalls('trigger'))->toBe(1)
        ->and($spy->messageCalls('tombstone'))->toBe(0);
});

it('measures the latency from the trigger watermark', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();
    $watermarkMs = now()->getTimestampMs();
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'last_ingested_at' => $watermarkMs,
        ]),
    ]);

    $latency = array_values(array_filter($spy->measures, fn (array $m): bool => $m['metric'] === 'wallet_refresh_latency'));

    expect($latency)->toHaveCount(1)
        ->and(abs($latency[0]['value']))->toBeLessThan(60.0);
});

it('refreshes once at the latest watermark when several triggers collapse', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();
    $watermarkMs = now()->getTimestampMs();
    $spy = new TriggerConsumerMetricsSpy;

    $base = [
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'last_ingested_at' => $watermarkMs,
    ];

    triggerConsumer($spy)->consume([
        triggerMessage($base),
        triggerMessage([...$base, 'last_ingested_at' => $watermarkMs + 1000, 'target_wallet_code' => 'gold']),
    ]);

    // Both messages count against the one refresh they collapsed into.
    expect($spy->messageCalls('trigger'))->toBe(2)
        ->and($spy->outcomeCalls('refreshed', 'none'))->toBe(1);
});

it('counts the tombstones apart from the triggers', function (): void {
    [$organization, $customer] = triggerConsumerEnvironment();
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
        new KafkaMessage('wallet_refresh_triggers', null),
    ]);

    expect($spy->messageCalls('trigger'))->toBe(1)
        ->and($spy->messageCalls('tombstone'))->toBe(1);
});

it('refreshes without a watermark to wait on when the payload has no subscription id', function (): void {
    [$organization, $customer] = triggerConsumerEnvironment();
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->outcomeCalls('refreshed', 'none'))->toBe(1);
});

it('walks past the customer when realtime usage is off for the organization', function (): void {
    $organization = Organization::factory()->create([
        'clickhouse_events_store' => true,
        'feature_flags' => ['realtime_usage'],
    ]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    Wallet::factory()->forCustomer($customer)->create(['rate_amount' => '1']);

    // Gate on, premium on, clickhouse on — but the env switch off
    // (Rails: `realtime_usage_enabled: "false"`).
    config([
        'lago.license' => 'premium-license-token',
        'lago.clickhouse.enabled' => true,
        'lago.realtime_usage.enabled' => false,
    ]);

    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->outcomeCalls('skipped', 'not_realtime'))->toBe(1)
        ->and($spy->outcomeCalls('refreshed', 'none'))->toBe(0);
});

it('walks past the customer when the organization does not read the clickhouse store', function (): void {
    $organization = Organization::factory()->create([
        'clickhouse_events_store' => false,
        'feature_flags' => ['realtime_usage'],
    ]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    Wallet::factory()->forCustomer($customer)->create(['rate_amount' => '1']);

    config([
        'lago.license' => 'premium-license-token',
        'lago.clickhouse.enabled' => true,
        'lago.realtime_usage.enabled' => true,
    ]);

    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->outcomeCalls('skipped', 'not_realtime'))->toBe(1);
});

it('refreshes when the organization deduplicates its events', function (): void {
    [$organization, $customer] = triggerConsumerEnvironment();
    $organization->forceFill(['clickhouse_deduplication_enabled' => true])->save();
    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->outcomeCalls('refreshed', 'none'))->toBe(1);
});

it('walks past the customer without an active wallet', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();

    // Replace the active wallet with a terminated one.
    $customer->wallets()->update(['status' => 1]);

    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'last_ingested_at' => now()->getTimestampMs(),
        ]),
    ]);

    expect($spy->outcomeCalls('skipped', 'no_wallet'))->toBe(1);
});

it('keeps consuming the batch when a refresh raises', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();

    $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
    Wallet::factory()->forCustomer($otherCustomer)->create(['rate_amount' => '1']);
    $otherSubscription = Subscription::factory()->for($otherCustomer)->create(['organization_id' => $organization->id]);

    // The first customer's refresh raises inside the bucket poll — a
    // readiness seam that blows up (Rails: the service raising
    // StaleObjectError). The consumer must carry on to the second one,
    // whose watermark the seam reports as caught up.
    app()->instance(UsageBucketReadiness::class, new class($subscription->id) implements UsageBucketReadiness
    {
        public function __construct(private readonly string $raiseFor) {}

        public function caughtUp(string $organizationId, string $subscriptionId, int $watermarkMs): bool
        {
            if ($subscriptionId === $this->raiseFor) {
                throw new RuntimeException('stale object');
            }

            return true;
        }
    });

    Log::spy();
    $spy = new TriggerConsumerMetricsSpy;

    $trigger = fn (Customer $c, Subscription $s): KafkaMessage => triggerMessage([
        'organization_id' => $organization->id,
        'customer_id' => $c->id,
        'subscription_id' => $s->id,
        'last_ingested_at' => now()->getTimestampMs(),
    ]);

    triggerConsumer($spy)->consume([$trigger($customer, $subscription), $trigger($otherCustomer, $otherSubscription)]);

    expect($spy->outcomeCalls('failed', 'refresh_raised'))->toBe(1)
        ->and($spy->outcomeCalls('refreshed', 'none'))->toBe(1);
});

it('counts the reason the refresh service reports', function (): void {
    [$organization, $customer, $subscription] = triggerConsumerEnvironment();

    // A stale watermark (older than the 30s cutoff) on a bucket that has
    // not caught up makes RealtimeRefreshService walk away without
    // refreshing.
    app()->instance(UsageBucketReadiness::class, new class([$subscription->id => false]) implements UsageBucketReadiness
    {
        public function __construct(private readonly array $buckets) {}

        public function caughtUp(string $organizationId, string $subscriptionId, int $watermarkMs): bool
        {
            return $this->buckets[$subscriptionId] ?? true;
        }
    });

    $staleWatermarkMs = CarbonImmutable::now()->subSeconds(120)->getTimestampMs();

    $spy = new TriggerConsumerMetricsSpy;

    triggerConsumer($spy)->consume([
        triggerMessage([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'last_ingested_at' => $staleWatermarkMs,
        ]),
    ]);

    expect($spy->outcomeCalls('skipped', 'stale_watermark'))->toBe(1)
        // no latency for a refresh that did not happen
        ->and(array_filter($spy->measures, fn (array $m): bool => $m['metric'] === 'wallet_refresh_latency'))->toBe([]);
});

it('flags the customers behind the consume deadline', function (): void {
    [$organization, $customer] = triggerConsumerEnvironment();

    Log::spy();

    $spy = new TriggerConsumerMetricsSpy;
    $consumer = triggerConsumer($spy);

    // The CONSUME_DEADLINE is a const (Rails stub_const's it to -1s);
    // drive the private check directly with an already-passed deadline.
    $deadline = now()->subSecond();

    $reached = (function () use ($deadline): bool {
        return $this->deadlineReached($deadline);
    })->call($consumer);

    expect($reached)->toBeTrue();

    Log::assertLogged('warning', fn (string $message): bool => str_contains($message, 'hit its deadline'));
});
