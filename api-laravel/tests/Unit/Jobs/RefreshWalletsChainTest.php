<?php

declare(strict_types=1);

require_once __DIR__.'/../Services/Wallets/WalletsTestHelpers.php';

uses()->group(
    'ledger:job:Clock.RefreshWalletsOngoingBalanceJob',
    'ledger:job:Customers.RefreshWalletJob',
    'ledger:svc:Customers.RefreshWalletsService',
    'ledger:svc:Invoices.CustomerUsageService',
);

use App\Models\Event;
use App\Models\Customer;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Customers\RefreshWalletJob;
use App\Services\Customers\RefreshWalletsService;
use App\Jobs\Clock\RefreshWalletsOngoingBalanceJob;

/**
 * The events → wallets loop closure: the flag raised by ingestion (and by
 * the balance services) is drained by the clock job into the per-customer
 * refresh, and the refresh allocates the ongoing usage on the wallets.
 */
function chainMetric(): BillableMetric
{
    return BillableMetric::factory()->create([
        'code' => 'api_calls',
        'field_name' => 'calls',
        'aggregation_type' => 1,
    ]);
}

function chainEvent(Customer $customer, string $subscriptionExternalId, string|int $value): Event
{
    return Event::factory()->create([
        'organization_id' => $customer->organization_id,
        'external_subscription_id' => $subscriptionExternalId,
        'code' => 'api_calls',
        'timestamp' => now()->utc(),
        'properties' => ['calls' => $value],
    ]);
}

it('enqueues the per-customer refresh for customers awaiting a wallet refresh', function (): void {
    config(['lago.license' => 'premium-license-token']);

    try {
        Queue::fake();

        [, $customer] = walletSetup();
        walletFor($customer);

        // A customer WITHOUT the flag must not be picked up.
        Customer::query()->whereKey($customer->id)->update(['awaiting_wallet_refresh' => true]);

        $other = Customer::factory()->create(['organization_id' => $customer->organization_id]);
        walletFor($other);

        // dispatchSync would land on the fake — run the clock job in place.
        (new RefreshWalletsOngoingBalanceJob)->handle();

        Queue::assertPushed(RefreshWalletJob::class, fn (RefreshWalletJob $job) => $job->customer->is($customer));
        Queue::assertNotPushed(fn (RefreshWalletJob $job) => $job->customer->is($other));
    } finally {
        config(['lago.license' => null]);

    }
});

it('skips the clock sweep without a premium license', function (): void {
    config(['lago.license' => null]);

    Queue::fake();

    [, $customer] = walletSetup();
    walletFor($customer);
    Customer::query()->whereKey($customer->id)->update(['awaiting_wallet_refresh' => true]);

    (new RefreshWalletsOngoingBalanceJob)->handle();

    Queue::assertNotPushed(RefreshWalletJob::class);
});

it('skips the refresh when no flag nor explicit wallet ids are set', function (): void {
    Queue::fake();

    [, $customer] = walletSetup();
    walletFor($customer);
    $customer->refresh();

    expect($customer->awaiting_wallet_refresh)->toBeFalse();

    (new RefreshWalletJob($customer))->handle();

    Queue::assertNothingPushed();
});

it('runs with explicit wallet ids even without the customer-wide flag', function (): void {
    [, $customer] = walletSetup();
    $wallet = walletFor($customer);
    $customer->refresh();

    // No usage: the refresh clears the sync marker without failing.
    (new RefreshWalletJob($customer, [(string) $wallet->id]))->handle();

    expect($wallet->fresh()->last_ongoing_balance_sync_at)->not->toBeNull();
});

it('allocates the current usage onto the wallet and clears the flag', function (): void {
    $metric = chainMetric();
    $customer = Customer::factory()->create(['organization_id' => $metric->organization_id]);
    $wallet = walletFor($customer, ['balance_cents' => 10000]);

    $subscription = App\Models\Subscription::factory()->for($customer)->create([
        'organization_id' => $customer->organization_id,
        'external_id' => 'chain-sub-1',
        'started_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
        'subscription_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
        'activated_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
    ]);

    App\Models\Charge::factory()->standard()->create([
        'organization_id' => $metric->organization_id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
        'properties' => ['amount' => '1'],
    ]);

    chainEvent($customer, $subscription->external_id, 25);

    $customer->flagWalletsForRefresh();

    $result = RefreshWalletsService::call(customer: $customer->refresh());

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect((int) $wallet->ongoing_usage_balance_cents)->toBe(2500)
        ->and((int) $wallet->ongoing_balance_cents)->toBe(7500)
        ->and($wallet->depleted_ongoing_balance)->toBeFalse()
        ->and($customer->refresh()->awaiting_wallet_refresh)->toBeFalse()
        ->and($wallet->last_ongoing_balance_sync_at)->not->toBeNull();
});

it('depletes the wallet and emits the webhook when the usage exceeds the balance', function (): void {
    Queue::fake();

    $metric = chainMetric();
    $customer = Customer::factory()->create(['organization_id' => $metric->organization_id]);
    $wallet = walletFor($customer, ['balance_cents' => 1000]);

    $subscription = App\Models\Subscription::factory()->for($customer)->create([
        'organization_id' => $customer->organization_id,
        'external_id' => 'chain-sub-2',
        'started_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
        'subscription_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
        'activated_at' => Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay(),
    ]);

    App\Models\Charge::factory()->standard()->create([
        'organization_id' => $metric->organization_id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
        'properties' => ['amount' => '1'],
    ]);

    chainEvent($customer, $subscription->external_id, 50);

    RefreshWalletsService::callBang(customer: $customer);

    $wallet->refresh();

    expect((int) $wallet->ongoing_usage_balance_cents)->toBe(5000)
        ->and($wallet->depleted_ongoing_balance)->toBeTrue();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn (App\Jobs\SendWebhookJob $job) => $job->webhookType === 'wallet.depleted_ongoing_balance');
});
