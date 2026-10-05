<?php

declare(strict_types=1);

uses()->group('ledger:svc:UsageMonitoring.CreateAlertService',
    'ledger:svc:UsageMonitoring.UpdateAlertService',
    'ledger:svc:UsageMonitoring.DestroyAlertService',
    'ledger:svc:UsageMonitoring.BaseService');

use App\Models\Plan;
use App\Models\Wallet;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Support\Facades\Queue;
use App\Models\UsageMonitoring\AlertThreshold;
use App\Services\UsageMonitoring\CreateAlertService;
use App\Services\UsageMonitoring\UpdateAlertService;
use App\Services\UsageMonitoring\DestroyAlertService;

/**
 * Port of spec/services/usage_monitoring/create_alert_service_spec.rb (core
 * scenarios) + the update/destroy specs' essentials.
 */
function alertOrg(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'sub-1',
        'status' => App\Enums\SubscriptionStatus::Active->value,
    ]);

    return [$organization, $customer, $plan, $subscription];
}

it('creates a subscription alert with thresholds', function (): void {
    Queue::fake();
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $result = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'current_usage_amount',
            'code' => 'warn_me',
            'name' => 'Warn',
            'thresholds' => [
                ['code' => 'warn', 'value' => '100', 'recurring' => false],
                ['value' => '1000', 'recurring' => false],
            ],
        ],
    );

    expect($result->success())->toBeTrue();

    $alert = $result->alert;

    expect($alert->alert_type)->toBe('current_usage_amount')
        ->and($alert->direction)->toBe('increasing')
        ->and($alert->subscription_external_id)->toBe('sub-1')
        ->and(App\Support\MoneyMath::toDecimalString($alert->previous_value))->toBe('0')
        ->and($alert->thresholds()->count())->toBe(2)
        ->and($alert->thresholds()->first()->code)->toBe('warn')
        ->and(App\Support\MoneyMath::toDecimalString($alert->thresholds()->first()->value))->toBe('100');
});

it('creates a decreasing wallet alert seeded with the current balance', function (): void {
    Queue::fake();
    [$organization, $customer] = alertOrg();

    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'balance_cents' => 5000,
    ]);

    $result = CreateAlertService::call(
        organization: $organization,
        alertable: $wallet,
        params: [
            'alert_type' => 'wallet_balance_amount',
            'code' => 'low_balance',
            'thresholds' => [['value' => '1000']],
        ],
    );

    expect($result->success())->toBeTrue()
        ->and($result->alert->direction)->toBe('decreasing')
        ->and($result->alert->wallet_id)->toBe($wallet->id)
        ->and(App\Support\MoneyMath::toDecimalString($result->alert->previous_value))->toBe('5000');
});

it('rejects a wallet alert type on a subscription and vice versa', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $subscriptionResult = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'wallet_balance_amount',
            'code' => 'x',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($subscriptionResult->success())->toBeFalse()
        ->and($subscriptionResult->getError()->getMessage())->toContain('invalid_type');

    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $walletResult = CreateAlertService::call(
        organization: $organization,
        alertable: $wallet,
        params: [
            'alert_type' => 'current_usage_amount',
            'code' => 'x',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($walletResult->success())->toBeFalse()
        ->and($walletResult->getError()->getMessage())->toContain('invalid_type');
});

it('validates the threshold matrix', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $base = [
        'alert_type' => 'current_usage_amount',
        'code' => 'warn_me',
    ];

    $cases = [
        [['value' => '10'], ['value' => '10']], // duplicate values
        [['value' => null]], // missing value
        [['value' => 'abc']], // non numeric
        [['value' => '-5', 'recurring' => true]], // negative recurring
    ];

    foreach ($cases as $thresholds) {
        $result = CreateAlertService::call(
            organization: $organization,
            alertable: $subscription,
            params: $base + ['thresholds' => $thresholds],
        );

        expect($result->success())->toBeFalse();
    }

    // Too many thresholds.
    $result = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: $base + ['thresholds' => array_map(fn (int $i): array => ['value' => (string) $i], range(1, 21))],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toContain('too_many_thresholds');

    // notify_on without triggered.
    $result = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: $base + ['thresholds' => [['value' => '10', 'notify_on' => ['resolved']]]],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toContain('triggered_is_mandatory');
});

it('rejects duplicate codes on the same wallet across alert types', function (): void {
    Queue::fake();
    [$organization, $customer] = alertOrg();

    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'balance_cents' => 5000,
    ]);

    $first = CreateAlertService::call(
        organization: $organization,
        alertable: $wallet,
        params: [
            'alert_type' => 'wallet_balance_amount',
            'code' => 'dupe',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($first->success())->toBeTrue();

    $second = CreateAlertService::call(
        organization: $organization,
        alertable: $wallet,
        params: [
            'alert_type' => 'wallet_credits_balance',
            'code' => 'dupe',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($second->success())->toBeFalse()
        ->and($second->getError()->getMessage())->toContain('value_already_exist');
});

it('rejects a second alert of the same type for one subscription', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $first = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'current_usage_amount',
            'code' => 'one',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($first->success())->toBeTrue();

    $second = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'current_usage_amount',
            'code' => 'two',
            'thresholds' => [['value' => '20']],
        ],
    );

    expect($second->success())->toBeFalse()
        ->and($second->getError()->getMessage())->toContain('alert_already_exists');
});

it('gates premium alert types on the organization integrations', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $result = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'lifetime_usage_amount',
            'code' => 'lifetime',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->getMessage())->toContain('feature_not_available');
});

it('requires billable metrics for billable-metric alert types', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $missing = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'billable_metric_current_usage_units',
            'code' => 'bm',
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($missing->success())->toBeFalse()
        ->and($missing->getError()->getMessage())->toContain('billable_metric');

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $found = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'billable_metric_current_usage_units',
            'code' => 'bm',
            'billable_metric_code' => $metric->code,
            'thresholds' => [['value' => '10']],
        ],
    );

    expect($found->success())->toBeTrue()
        ->and($found->alert->billable_metric_id)->toBe($metric->id);
});

it('replaces thresholds on update and discards on destroy', function (): void {
    [$organization, $customer, $plan, $subscription] = alertOrg();

    $createResult = CreateAlertService::call(
        organization: $organization,
        alertable: $subscription,
        params: [
            'alert_type' => 'current_usage_amount',
            'code' => 'warn_me',
            'thresholds' => [['value' => '100']],
        ],
    );

    $alert = $createResult->alert;

    $updateResult = UpdateAlertService::call(
        alert: $alert,
        params: [
            'name' => 'Renamed',
            'thresholds' => [['code' => 'a', 'value' => '1'], ['code' => 'b', 'value' => '2']],
        ],
    );

    expect($updateResult->success())->toBeTrue()
        ->and($updateResult->alert->name)->toBe('Renamed')
        ->and($alert->thresholds()->orderBy('value')->pluck('code')->all())->toBe(['a', 'b']);

    $destroyResult = DestroyAlertService::call(alert: $alert);

    expect($destroyResult->success())->toBeTrue()
        ->and($alert->fresh()->deleted_at)->not->toBeNull()
        ->and(Alert::query()->where('id', $alert->id)->count())->toBe(0)
        ->and(AlertThreshold::query()->where('usage_monitoring_alert_id', $alert->id)->count())->toBe(0);
});
