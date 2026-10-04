<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/customers/:external_id/current_usage',
    'ledger:rest:GET:/api/v1/customers/:external_id/projected_usage',
);

use App\Models\Event;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\BillableMetric;

/**
 * Port of Rails' spec/requests/api/v1/customers/usage_controller_spec.rb
 * (the current_usage / projected_usage scenarios the port supports).
 */
function usageApiOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function usageApiFixture(Organization $organization): array
{
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api_calls',
        'field_name' => 'calls',
        'aggregation_type' => 1,
    ]);

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $start = Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay();

    $subscription = Subscription::factory()->for($customer)->create([
        'organization_id' => $organization->id,
        'external_id' => 'usage-api-sub',
        'started_at' => $start,
        'subscription_at' => $start,
        'activated_at' => $start,
    ]);

    Charge::factory()->standard()->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
        'properties' => ['amount' => '1'],
    ]);

    Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'code' => 'api_calls',
        'timestamp' => now()->utc()->subDays(1),
        'properties' => ['calls' => 20],
    ]);

    return [$customer, $subscription];
}

it('returns the customer current usage', function (): void {
    [$organization, $apiKey] = usageApiOrganization();
    [$customer, $subscription] = usageApiFixture($organization);

    $this->getJson(
        "/api/v1/customers/{$customer->external_id}/current_usage?external_subscription_id={$subscription->external_id}",
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('customer_usage.currency', 'EUR')
            ->where('customer_usage.amount_cents', 2000)
            ->where('customer_usage.total_amount_cents', 2000)
            ->where('customer_usage.taxes_amount_cents', 0)
            ->where('customer_usage.lago_invoice_id', null)
            ->where('customer_usage.from_datetime', fn ($v) => is_string($v) && $v !== '')
            ->where('customer_usage.charges_usage.charges_usage.0.units', '20.0')
            ->where('customer_usage.charges_usage.charges_usage.0.events_count', 1)
            ->where('customer_usage.charges_usage.charges_usage.0.amount_cents', 2000)
            ->etc();
    });
});

it('fails not_found for an unknown customer', function (): void {
    [$organization, $apiKey] = usageApiOrganization();

    $this->getJson(
        '/api/v1/customers/missing/current_usage?external_subscription_id=sub',
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertNotFound();
});

it('fails not_allowed when the subscription is not active', function (): void {
    [$organization, $apiKey] = usageApiOrganization();
    [$customer, $subscription] = usageApiFixture($organization);

    $subscription->update(['status' => 'terminated']);

    $this->getJson(
        "/api/v1/customers/{$customer->external_id}/current_usage?external_subscription_id={$subscription->external_id}",
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertMethodNotAllowed()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('status', 405)
            ->where('error', 'Method Not Allowed')
            ->where('code', 'no_active_subscription');
    });
});

it('returns the projected usage when the organization opted in', function (): void {
    config(['lago.license' => 'premium-license-token']);

    try {
        $organization = Organization::factory()->create([
            'premium_integrations' => ['projected_usage'],
        ]);
        $apiKey = $organization->apiKeys()->first();
        [$customer, $subscription] = usageApiFixture($organization);

        $this->getJson(
            "/api/v1/customers/{$customer->external_id}/projected_usage?external_subscription_id={$subscription->external_id}",
            ['Authorization' => 'Bearer '.$apiKey->value],
        )->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->where('customer_projected_usage.currency', 'EUR')
                ->where('customer_projected_usage.amount_cents', 2000)
                ->where('customer_projected_usage.projected_amount_cents', fn ($v) => is_int($v) && $v > 0)
                ->where('customer_projected_usage.charges_usage.charges_usage.0.projected_units', fn ($v) => (float) $v > 20.0)
                ->etc();
        });
    } finally {
        config(['lago.license' => null]);

    }
});

it('forbids the projected usage without the organization flag', function (): void {
    [$organization, $apiKey] = usageApiOrganization();
    [$customer, $subscription] = usageApiFixture($organization);

    $this->getJson(
        "/api/v1/customers/{$customer->external_id}/projected_usage?external_subscription_id={$subscription->external_id}",
        ['Authorization' => 'Bearer '.$apiKey->value],
    )->assertForbidden()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->where('status', 403)
            ->where('error', 'Forbidden')
            ->where('code', 'projected_usage_not_enabled');
    });
});

// The v2 mirror carries the beta header on the same handlers.
it('mirrors the usage endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = usageApiOrganization();
    [$customer, $subscription] = usageApiFixture($organization);

    $response = $this->getJson(
        "/api/v2/customers/{$customer->external_id}/current_usage?external_subscription_id={$subscription->external_id}",
        ['Authorization' => 'Bearer '.$apiKey->value],
    );

    $response->assertOk()->assertHeader('X-Lago-Endpoint-Status', 'beta');
});
