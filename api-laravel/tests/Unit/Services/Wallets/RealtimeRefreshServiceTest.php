<?php

declare(strict_types=1);

uses()->group('ledger:svc:Wallets.RealtimeRefreshService');

use App\Models\Wallet;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Metrics\MetricsSink;
use App\Support\Metrics\RealtimeUsageMetrics;
use App\Services\Wallets\RealtimeRefreshService;
use App\Services\Wallets\Buckets\UsageBucketReadiness;
use App\Services\Wallets\Buckets\PendingUsageBucketReadiness;

/**
 * Port of Rails' spec/services/wallets/realtime_refresh_service_spec.rb —
 * the inline refresh behind the wallet refresh triggers consumer: the
 * bucket watermark wait, the skip reasons, and the delegation to
 * Customers::RefreshWalletsService. The Clickhouse::UsageBucket read is
 * the UsageBucketReadiness seam (TODO(port) — the bucket sink is not
 * landed), so the catch-up scenarios drive the seam.
 */
final class MetricsSpy implements MetricsSink
{
    /** @var list<array{metric: string, value: float}> */
    public array $measures = [];

    public function increment(string $metric, array $labels = [], int $by = 1): void {}

    public function measure(string $metric, array $labels = [], float $value = 0.0): void
    {
        $this->measures[] = ['metric' => $metric, 'value' => $value];
    }
}

/**
 * @param  array<string, bool>  $buckets  subscription_id => caught up?
 */
function readinessFake(array $buckets): UsageBucketReadiness
{
    return new class($buckets) implements UsageBucketReadiness
    {
        public function __construct(private readonly array $buckets) {}

        public function caughtUp(string $organizationId, string $subscriptionId, int $watermarkMs): bool
        {
            return $this->buckets[$subscriptionId] ?? true;
        }
    };
}

function refreshEnvironment(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->for($customer)->create(['organization_id' => $organization->id]);
    Wallet::factory()->forCustomer($customer)->create(['rate_amount' => '1']);

    return [$organization, $customer, $subscription];
}

function watermark(int $secondsAgo = 0): int
{
    return CarbonImmutable::now()->subSeconds($secondsAgo)->getTimestampMs();
}

it('refreshes the customer wallets when the buckets are caught up', function (): void {
    [$organization, $customer, $subscription] = refreshEnvironment();

    $spy = new MetricsSpy;
    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: $customer->id,
        expectedIngestedAt: [$subscription->id => watermark()],
        bucketReadiness: readinessFake([$subscription->id => true]),
        metrics: new RealtimeUsageMetrics($spy),
    );

    expect($result->success())->toBeTrue()
        ->and($result->reason)->toBeNull()
        ->and($result->wallets)->toHaveCount(1)
        // the sweep flag the refresh clears
        ->and($customer->fresh()->awaiting_wallet_refresh)->toBeFalse()
        ->and(array_filter($spy->measures, fn (array $m): bool => $m['metric'] === 'wallet_refresh_duration'))->not->toBe([]);
});

it('skips with a stale watermark instead of sleeping on the deadline', function (): void {
    [$organization, $customer, $subscription] = refreshEnvironment();

    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: $customer->id,
        expectedIngestedAt: [$subscription->id => watermark(120)],
        bucketReadiness: readinessFake([$subscription->id => false]),
    );

    expect($result->success())->toBeTrue()
        ->and($result->reason)->toBe('stale_watermark')
        ->and($customer->fresh()->awaiting_wallet_refresh)->toBeFalse();
});

it('times out when the buckets never catch up inside the wait window', function (): void {
    [$organization, $customer, $subscription] = refreshEnvironment();

    // Fresh watermark (not stale) + never caught up → the poll waits out
    // BUCKET_WAIT_TIMEOUT. Shrink the interval's cost by accepting the
    // real 5s wait once; the reason is what matters.
    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: $customer->id,
        expectedIngestedAt: [$subscription->id => watermark()],
        bucketReadiness: readinessFake([$subscription->id => false]),
    );

    expect($result->success())->toBeTrue()
        ->and($result->reason)->toBe('bucket_wait_timeout');
});

it('does not wait when no watermark is expected', function (): void {
    [$organization, $customer] = refreshEnvironment();

    // A never-caught-up seam would poison the wait if the service polled
    // without watermarks; it must go straight to the refresh.
    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: $customer->id,
        expectedIngestedAt: [],
        bucketReadiness: readinessFake(['*' => false]),
    );

    expect($result->success())->toBeTrue()
        ->and($result->reason)->toBeNull();
});

it('returns without refreshing an unknown customer', function (): void {
    [$organization, $customer] = refreshEnvironment();

    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: '00000000-0000-4000-8000-000000000000',
    );

    expect($result->success())->toBeTrue()
        ->and($result->wallets)->toBe([]);
});

it('warns when the targeted wallet codes match no active wallet', function (): void {
    [$organization, $customer] = refreshEnvironment();
    Wallet::query()->where('customer_id', $customer->id)->update(['code' => 'silver']);

    Illuminate\Support\Facades\Log::spy();

    $result = RealtimeRefreshService::call(
        organizationId: $organization->id,
        customerId: $customer->id,
        walletCodes: ['gold'],
        bucketReadiness: new PendingUsageBucketReadiness,
    );

    expect($result->success())->toBeTrue()
        ->and($result->reason)->toBeNull();

    Illuminate\Support\Facades\Log::assertLogged(
        'warning',
        fn (string $message): bool => str_contains($message, 'targeted unknown wallet codes'),
    );
});
