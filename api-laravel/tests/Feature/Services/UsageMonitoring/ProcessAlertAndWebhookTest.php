<?php

declare(strict_types=1);

uses()->group('ledger:svc:UsageMonitoring.ProcessAlertService',
    'ledger:svc:UsageMonitoring.ProcessWalletAlertsService',
    'ledger:svc:UsageMonitoring.TrackSubscriptionActivityService',
    'ledger:svc:UsageMonitoring.ProcessOrganizationSubscriptionActivitiesService',
    'ledger:svc:UsageMonitoring.ProcessAllSubscriptionActivitiesService',
    'ledger:svc:Webhooks.UsageMonitoring.AlertTriggeredService',
    'ledger:ser:V1.UsageMonitoring.TriggeredAlertSerializer',
    'ledger:job:UsageMonitoring.ProcessOrganizationSubscriptionActivitiesJob');

use App\Models\Plan;
use App\Models\Wallet;
use App\Models\Customer;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\WebhookEndpoint;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Support\Facades\Queue;
use App\Models\UsageMonitoring\AlertThreshold;
use App\Models\UsageMonitoring\TriggeredAlert;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Services\UsageMonitoring\ProcessAlertService;
use App\Services\UsageMonitoring\ProcessWalletAlertsService;
use App\Services\UsageMonitoring\TrackSubscriptionActivityService;
use App\Services\UsageMonitoring\ProcessAllSubscriptionActivitiesService;
use App\Services\UsageMonitoring\ProcessOrganizationSubscriptionActivitiesService;

/**
 * Ports of spec/services/usage_monitoring/process_alert_service_spec.rb,
 * process_wallet_alerts_service_spec.rb and the alert.triggered webhook
 * builder spec (core scenarios).
 */
function paScenario(string $alertType = 'current_usage_amount', string $direction = 'increasing'): array
{
    $organization = Organization::factory()->withoutWebhookEndpoint()->create();
    $customer = Customer::factory()->for($organization)->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'sub-1',
        'status' => 1,
    ]);

    $alert = Alert::factory()->forSubscription('sub-1')->create([
        'organization_id' => $organization->id,
        'alert_type' => $alertType,
        'code' => 'warn',
        'direction' => $direction,
        'previous_value' => '0',
    ]);
    AlertThreshold::query()->create([
        'organization_id' => $organization->id,
        'usage_monitoring_alert_id' => $alert->id,
        'code' => 'warn',
        'value' => '100',
        'recurring' => false,
    ]);
    $alert->setRelation('thresholds', $alert->thresholds()->get());

    return [$organization, $customer, $plan, $subscription, $alert];
}

it('records a triggered alert and webhook on threshold crossing', function (): void {
    Queue::fake();
    [$organization, , , $subscription, $alert] = paScenario();
    WebhookEndpoint::factory()->forOrganization($organization)->create();

    $result = ProcessAlertService::call(
        alert: $alert,
        alertable: $subscription,
        currentMetrics: ['amount_cents' => 150],
    );

    expect($result->success())->toBeTrue();

    $triggered = TriggeredAlert::query()->where('usage_monitoring_alert_id', $alert->id)->first();

    expect($triggered)->not->toBeNull()
        ->and(App\Support\MoneyMath::toDecimalString($triggered->current_value))->toBe('150')
        ->and($triggered->crossed_thresholds)->toBe([
            ['code' => 'warn', 'value' => '100.0', 'recurring' => false],
        ])
        ->and($triggered->subscription_id)->toBe($subscription->id)
        ->and(App\Support\MoneyMath::toDecimalString($alert->fresh()->previous_value))->toBe('150')
        ->and($alert->fresh()->last_processed_at)->not->toBeNull();

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => $job->webhookType === 'alert.triggered');
});

it('refreshes the baseline without triggering below the threshold', function (): void {
    Queue::fake();
    [, , , $subscription, $alert] = paScenario();

    ProcessAlertService::call(alert: $alert, alertable: $subscription, currentMetrics: ['amount_cents' => 50]);

    expect(TriggeredAlert::query()->where('usage_monitoring_alert_id', $alert->id)->count())->toBe(0)
        ->and(App\Support\MoneyMath::toDecimalString($alert->fresh()->previous_value))->toBe('50');

    Queue::assertNotPushed(SendWebhookJob::class);
});

it('does not re-trigger between the same value', function (): void {
    Queue::fake();
    [$organization, , , $subscription, $alert] = paScenario();

    // First evaluation crosses and advances the baseline to 150.
    ProcessAlertService::call(alert: $alert, alertable: $subscription, currentMetrics: ['amount_cents' => 150]);

    // Second evaluation at the same value: no movement, no crossing.
    $alert->refresh();
    $alert->setRelation('thresholds', $alert->thresholds()->get());
    ProcessAlertService::call(alert: $alert, alertable: $subscription, currentMetrics: ['amount_cents' => 150]);

    expect(TriggeredAlert::query()->count())->toBe(1);
});

it('evaluates every wallet alert against live balances', function (): void {
    Queue::fake();
    [$organization, $customer] = paScenario('wallet_balance_amount', 'decreasing');

    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'balance_cents' => 50,
    ]);

    $alert = $organization->alerts()->usingWallet()->first();
    $alert->update(['wallet_id' => $wallet->id, 'subscription_external_id' => null, 'previous_value' => '1000']);
    $alert->setRelation('thresholds', $alert->thresholds()->get());

    $result = ProcessWalletAlertsService::call(wallet: $wallet->refresh());

    expect($result->success())->toBeTrue()
        ->and(TriggeredAlert::query()->where('wallet_id', $wallet->id)->count())->toBe(1)
        ->and(App\Support\MoneyMath::toDecimalString($alert->fresh()->previous_value))->toBe('50');
});

it('serializes the alert.triggered webhook payload per Rails', function (): void {
    Queue::fake();
    [$organization, $customer, , $subscription, $alert] = paScenario();

    WebhookEndpoint::factory()->forOrganization($organization)->create();

    ProcessAlertService::call(alert: $alert, alertable: $subscription, currentMetrics: ['amount_cents' => 150]);

    // Run the webhook builder directly (the queued job would resolve it) and
    // assert the persisted payload per the Rails serializer spec.
    $triggered = TriggeredAlert::query()->first();
    App\Services\Webhooks\UsageMonitoring\AlertTriggeredService::call(object: $triggered);

    $webhook = App\Models\Webhook::query()->where('webhook_type', 'alert.triggered')->first();

    expect($webhook)->not->toBeNull();

    $payload = $webhook->payload;

    expect($payload['object_type'])->toBe('triggered_alert')
        ->and($payload['organization_id'])->toBe($organization->id)
        ->and($payload['triggered_alert']['lago_alert_id'])->toBe($alert->id)
        ->and($payload['triggered_alert']['alert_code'])->toBe('warn')
        ->and($payload['triggered_alert']['external_customer_id'])->not->toBeNull()
        ->and($payload['triggered_alert']['crossed_thresholds'])->toBe([
            ['code' => 'warn', 'value' => '100.0', 'recurring' => false],
        ]);
});

it('deduplicates subscription activities by subscription', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, , , $subscription] = paScenario();

    config(['lago.license' => null]);
    // Non-premium: tracking is a no-op.
    TrackSubscriptionActivityService::call(
        subscription: $subscription,
        date: Carbon\CarbonImmutable::today(),
        organization: $organization,
    );
    expect(SubscriptionActivity::query()->count())->toBe(0);

    config(['lago.license' => 'premium-license-token']);
    $organization->premium_integrations = ['lifetime_usage'];
    $organization->save();

    TrackSubscriptionActivityService::call(
        subscription: $subscription,
        date: Carbon\CarbonImmutable::today(),
        organization: $organization,
    );
    TrackSubscriptionActivityService::call(
        subscription: $subscription,
        date: Carbon\CarbonImmutable::today(),
        organization: $organization,
    );

    expect(SubscriptionActivity::query()->count())->toBe(1);

    // The fan-out flags and enqueues exactly one job batch.
    $result = ProcessOrganizationSubscriptionActivitiesService::call(organization: $organization->refresh());

    expect($result->nb_jobs_enqueued)->toBe(1)
        ->and($subscription->subscriptionActivities()->first()->enqueued)->toBeTrue();

    // Second sweep: nothing pending.
    $again = ProcessOrganizationSubscriptionActivitiesService::call(organization: $organization);
    expect($again->nb_jobs_enqueued)->toBe(0);

    // The clock service fans out one org job per pending org. (Depending on
    // the queue driver the earlier sweep's job may not have consumed the row,
    // and the unique index keeps a new one from coexisting with it.)
    Queue::fake();
    SubscriptionActivity::query()->delete();
    SubscriptionActivity::insertFor($subscription, (string) $organization->id);
    ProcessAllSubscriptionActivitiesService::call();
    Queue::assertPushed(App\Jobs\UsageMonitoring\ProcessOrganizationSubscriptionActivitiesJob::class);
});

it('skips wallet processing for inactive alerts flow when no alerts exist', function (): void {
    [$organization, $customer] = paScenario();
    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    Queue::fake();
    $result = ProcessWalletAlertsService::call(wallet: $wallet);

    expect($result->success())->toBeTrue();
    Queue::assertNothingPushed();
});
