<?php

declare(strict_types=1);

uses()->group('ledger:rest:GET:/api/v1/subscriptions/:external_id/alerts',
    'ledger:rest:GET:/api/v1/subscriptions/:external_id/alerts/:code',
    'ledger:rest:POST:/api/v1/subscriptions/:external_id/alerts',
    'ledger:rest:PATCH:/api/v1/subscriptions/:external_id/alerts/:code',
    'ledger:rest:PUT:/api/v1/subscriptions/:external_id/alerts/:code',
    'ledger:rest:DELETE:/api/v1/subscriptions/:external_id/alerts',
    'ledger:rest:DELETE:/api/v1/subscriptions/:external_id/alerts/:code',
    'ledger:rest:GET:/api/v1/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:GET:/api/v1/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:POST:/api/v1/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:PATCH:/api/v1/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:PUT:/api/v1/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:DELETE:/api/v1/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:DELETE:/api/v1/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:GET:/api/v1/subscriptions/:external_id/lifetime_usage',
    'ledger:rest:PATCH:/api/v1/subscriptions/:external_id/lifetime_usage',
    'ledger:rest:PUT:/api/v1/subscriptions/:external_id/lifetime_usage',
    'ledger:rest:GET:/api/v2/subscriptions/:external_id/alerts',
    'ledger:rest:GET:/api/v2/subscriptions/:external_id/alerts/:code',
    'ledger:rest:POST:/api/v2/subscriptions/:external_id/alerts',
    'ledger:rest:PATCH:/api/v2/subscriptions/:external_id/alerts/:code',
    'ledger:rest:PUT:/api/v2/subscriptions/:external_id/alerts/:code',
    'ledger:rest:DELETE:/api/v2/subscriptions/:external_id/alerts',
    'ledger:rest:DELETE:/api/v2/subscriptions/:external_id/alerts/:code',
    'ledger:rest:GET:/api/v2/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:GET:/api/v2/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:POST:/api/v2/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:PATCH:/api/v2/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:PUT:/api/v2/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:DELETE:/api/v2/customers/:external_id/wallets/:code/alerts',
    'ledger:rest:DELETE:/api/v2/customers/:external_id/wallets/:code/alerts/:code',
    'ledger:rest:GET:/api/v2/subscriptions/:external_id/lifetime_usage',
    'ledger:rest:PATCH:/api/v2/subscriptions/:external_id/lifetime_usage',
    'ledger:rest:PUT:/api/v2/subscriptions/:external_id/lifetime_usage',
    'ledger:svc:UsageMonitoring.Alerts.CreateBatchService',
    'ledger:svc:UsageMonitoring.Alerts.DestroyAllService',
    'ledger:ser:V1.UsageMonitoring.AlertSerializer',
    'ledger:ser:V1.LifetimeUsageSerializer');

use App\Models\Plan;
use App\Models\ApiKey;
use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of the Rails request specs for the alert CRUD endpoints
 * (spec/requests/api/v1/subscriptions/alerts_request_spec.rb and the wallets
 * counterpart) + the lifetime_usage endpoints — envelope shapes, param
 * handling, and the nested-scope resolution.
 */
function umRestOrg(): array
{
    $organization = Organization::factory()->create();
    App\Models\BillingEntity::factory()->for($organization)->create();
    $customer = Customer::factory()->for($organization)->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'sub-1',
        'status' => 1,
    ]);
    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'code' => 'wal-1',
        'balance_cents' => 5000,
    ]);

    return [$organization, $customer, $plan, $subscription, $wallet];
}

/** @return array{0: Organization, 1: array<string, string>} */
function umAuth(Organization $organization): array
{
    $apiKey = ApiKey::create([
        'organization_id' => $organization->id,
        'value' => (string) Str::uuid(),
        'permissions' => [],
    ]);

    return [$organization, ['Authorization' => 'Bearer '.$apiKey->value]];
}

it('creates and lists subscription alerts over REST', function (): void {
    [, $headers] = umAuth(...umRestOrg());

    $this->postJson('/api/v1/subscriptions/sub-1/alerts', [
        'alert' => [
            'alert_type' => 'current_usage_amount',
            'code' => 'warn_me',
            'name' => 'Warn',
            'thresholds' => [['code' => 'w', 'value' => '100', 'recurring' => false]],
        ],
    ], $headers)->assertOk();

    $body = $this->getJson('/api/v1/subscriptions/sub-1/alerts', $headers)->assertOk()->json('alerts.0');

    expect($body['code'])->toBe('warn_me')
        ->and($body['alert_type'])->toBe('current_usage_amount')
        ->and($body['external_subscription_id'])->toBe('sub-1')
        ->and($body['direction'])->toBe('increasing')
        ->and($body['thresholds'])->toHaveCount(1)
        ->and($body['thresholds'][0]['code'])->toBe('w');

    $this->getJson('/api/v1/subscriptions/sub-1/alerts', $headers)
        ->assertOk()
        ->assertJsonPath('meta.total_count', 1);

    $this->getJson('/api/v1/subscriptions/sub-1/alerts/warn_me', $headers)
        ->assertOk()
        ->assertJsonPath('alert.code', 'warn_me');

    $this->putJson('/api/v1/subscriptions/sub-1/alerts/warn_me', [
        'alert' => ['name' => 'Renamed'],
    ], $headers)->assertOk()->assertJsonPath('alert.name', 'Renamed');
});

it('creates a batch of subscription alerts atomically', function (): void {
    [$organization, $headers] = umAuth(...umRestOrg());

    $metric = BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/subscriptions/sub-1/alerts', [
        'alerts' => [
            ['alert_type' => 'current_usage_amount', 'code' => 'a1', 'thresholds' => [['value' => '10']]],
            ['alert_type' => 'billable_metric_current_usage_units', 'code' => 'a2',
                'billable_metric_code' => $metric->code, 'thresholds' => [['value' => '20']]],
        ],
    ], $headers)->assertOk();
    expect(count($this->getJson('/api/v1/subscriptions/sub-1/alerts', $headers)->json('alerts')))->toBe(2);

    // A failing member rolls the whole batch back.
    $this->postJson('/api/v1/subscriptions/sub-1/alerts', [
        'alerts' => [
            ['alert_type' => 'current_usage_amount', 'code' => 'b1', 'thresholds' => [['value' => '10']]],
            ['alert_type' => 'current_usage_amount', 'code' => 'b2', 'thresholds' => []],
        ],
    ], $headers)->assertStatus(422);

    expect(Alert::query()->where('organization_id', $organization->id)->where('code', 'b1')->exists())->toBeFalse();
});

it('destroys single and all subscription alerts', function (): void {
    [$organization, $headers] = umAuth(...umRestOrg());

    Alert::query()->create([
        'organization_id' => $organization->id,
        'subscription_external_id' => 'sub-1',
        'alert_type' => 'current_usage_amount',
        'code' => 'x1',
        'direction' => 'increasing',
    ]);
    Alert::query()->create([
        'organization_id' => $organization->id,
        'subscription_external_id' => 'sub-1',
        'alert_type' => 'lifetime_usage_amount',
        'code' => 'x2',
        'direction' => 'increasing',
    ]);

    $this->deleteJson('/api/v1/subscriptions/sub-1/alerts/x1', [], $headers)->assertOk();
    expect(Alert::query()->where('organization_id', $organization->id)->where('code', 'x1')->count())->toBe(0);

    $this->deleteJson('/api/v1/subscriptions/sub-1/alerts', [], $headers)->assertOk();
    expect(Alert::query()->where('organization_id', $organization->id)->where('subscription_external_id', 'sub-1')->count())->toBe(0);
});

it('answers the not_found envelope for unknown subscription alerts', function (): void {
    [, $headers] = umAuth(...umRestOrg());

    $this->getJson('/api/v1/subscriptions/sub-1/alerts/nope', $headers)->assertStatus(404);
    $this->getJson('/api/v1/subscriptions/unknown/alerts', $headers)->assertStatus(404);
});

it('creates wallet alerts over the customers nested route', function (): void {
    Queue::fake();
    [$organization, $customer, , , $wallet] = umRestOrg();
    [, $headers] = umAuth($organization);

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets/wal-1/alerts', [
        'alert' => [
            'alert_type' => 'wallet_balance_amount',
            'code' => 'low_balance',
            'thresholds' => [['value' => '1000']],
        ],
    ], $headers)->assertOk();

    $body = $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets/wal-1/alerts', $headers)
        ->assertOk()->json('alerts.0');

    expect($body['direction'])->toBe('decreasing')
        ->and($body['lago_wallet_id'])->toBe($wallet->id);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/wallets/wal-1/alerts', [], $headers)->assertOk();
    expect(Alert::query()->where('wallet_id', $wallet->id)->count())->toBe(0);
});

it('rejects subscription alert types on the wallet route symmetrically', function (): void {
    [$organization, $customer] = umRestOrg();
    [, $headers] = umAuth($organization);

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets/wal-1/alerts', [
        'alert' => [
            'alert_type' => 'current_usage_amount',
            'code' => 'wrong',
            'thresholds' => [['value' => '10']],
        ],
    ], $headers)->assertStatus(422);
});

it('shows and updates the lifetime usage over REST', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, , , $subscription] = umRestOrg();
    [, $headers] = umAuth($organization);

    $organization->premium_integrations = ['lifetime_usage'];
    $organization->save();

    $subscription->createLifetimeUsage(['current_usage_amount_cents' => 1234]);

    $body = $this->getJson('/api/v1/subscriptions/sub-1/lifetime_usage', $headers)
        ->assertOk()->json('lifetime_usage');

    expect($body['external_subscription_id'])->toBe('sub-1')
        ->and($body['current_usage_amount_cents'])->toBe(1234);

    $this->patchJson('/api/v1/subscriptions/sub-1/lifetime_usage', [
        'lifetime_usage' => ['external_historical_usage_amount_cents' => 555],
    ], $headers)->assertOk()
        ->assertJsonPath('lifetime_usage.external_historical_usage_amount_cents', 555);

    // v2 mirrors the same controller (the beta header itself is covered by the
    // middleware tests).
    $this->getJson('/api/v2/subscriptions/sub-1/lifetime_usage', $headers)->assertOk();
});

it('answers not_found for a subscription without lifetime usage', function (): void {
    [, $headers] = umAuth(...umRestOrg());

    $this->getJson('/api/v1/subscriptions/sub-1/lifetime_usage', $headers)->assertStatus(404);
});
