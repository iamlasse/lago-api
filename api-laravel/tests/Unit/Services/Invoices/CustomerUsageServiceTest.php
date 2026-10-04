<?php

declare(strict_types=1);

uses()->group(
    'ledger:svc:Invoices.CustomerUsageService',
    'ledger:svc:Fees.ProjectionService',
    'ledger:ser:V1.Customers.UsageSerializer',
    'ledger:ser:V1.Customers.ChargeUsageSerializer',
    'ledger:ser:V1.Customers.ProjectedUsageSerializer',
);

use Carbon\Carbon;
use App\Models\Event;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Services\Invoices\CustomerUsageService;

/**
 * Port of Rails' spec/services/invoices/customer_usage_service_spec.rb
 * (the current-usage scenarios the port supports).
 *
 * The usage is always computed for the CURRENT period, so the fixtures are
 * now-relative: a subscription started a few days ago, events inside it.
 */
function usagePeriodStart(): \Carbon\CarbonImmutable
{
    return \Carbon\CarbonImmutable::now()->utc()->subDays(5)->startOfDay();
}
function usageMetric(array $attributes = []): BillableMetric
{
    return BillableMetric::factory()->create($attributes + [
        'code' => 'api_calls',
        'field_name' => 'calls',
        'aggregation_type' => 1,
    ]);
}

function usageCustomer(BillableMetric $metric): Customer
{
    return Customer::factory()->create(['organization_id' => $metric->organization_id]);
}

function usageSubscription(Customer $customer, array $attributes = []): Subscription
{
    return Subscription::factory()->for($customer)->create($attributes + [
        'organization_id' => $customer->organization_id,
        'external_id' => 'usage-sub-1',
        'started_at' => usagePeriodStart(),
        'subscription_at' => usagePeriodStart(),
        'activated_at' => usagePeriodStart(),
    ]);
}

function usageCharge(BillableMetric $metric, ?Subscription $subscription = null, array $attributes = []): Charge
{
    return Charge::factory()->standard($attributes['properties'] ?? ['amount' => '150'])->create(
        $attributes + [
            'organization_id' => $metric->organization_id,
            'billable_metric_id' => $metric->id,
            'plan_id' => $subscription?->plan_id,
        ],
    );
}

function usageEvent(Subscription $subscription, string $timestamp, string|int $value): Event
{
    return Event::factory()->create([
        'organization_id' => $subscription->organization_id,
        'external_subscription_id' => $subscription->external_id,
        'code' => 'api_calls',
        'timestamp' => \Carbon\CarbonImmutable::parse($timestamp),
        'properties' => ['calls' => $value],
    ]);
}

it('computes the current usage of the subscription period from the events', function (): void {
    $metric = usageMetric();
    $customer = usageCustomer($metric);
    $subscription = usageSubscription($customer);
    $charge = usageCharge($metric, $subscription, ['properties' => ['amount' => '2']]);

    usageEvent($subscription, now()->utc()->subDays(2)->toIso8601String(), 10);
    usageEvent($subscription, now()->utc()->subDays(1)->toIso8601String(), 5);

    $result = CustomerUsageService::withExternalIds(
        customerExternalId: $customer->external_id,
        externalSubscriptionId: $subscription->external_id,
        organizationId: $customer->organization_id,
    );

    expect($result->success())->toBeTrue();

    $usage = $result->usage;

    // 15 units * 2.00 EUR.
    expect($usage->amountCents)->toBe(3000)
        ->and($usage->currency)->toBe('EUR')
        ->and($usage->taxesAmountCents)->toBe(0)
        ->and($usage->totalAmountCents)->toBe(3000)
        ->and($usage->fromDatetime)->toStartWith(now()->utc()->startOfMonth()->toIso8601String());

    expect(count($usage->fees))->toBe(1);
    expect((int) $usage->fees[0]->amount_cents)->toBe(3000);
    expect((int) $usage->fees[0]->events_count)->toBe(2);

    // Nothing is persisted.
    expect($result->invoice->exists)->toBeFalse();
});

it('serves the charge usage breakdown for the serializer', function (): void {
    $metric = usageMetric();
    $customer = usageCustomer($metric);
    $subscription = usageSubscription($customer);
    usageCharge($metric, $subscription, ['properties' => ['amount' => '1']]);

    usageEvent($subscription, now()->utc()->subDays(2)->toIso8601String(), 3);

    $result = CustomerUsageService::withExternalIds(
        customerExternalId: $customer->external_id,
        externalSubscriptionId: $subscription->external_id,
        organizationId: $customer->organization_id,
    );

    $payload = (new App\Serializers\V1\Customers\UsageSerializer(
        $result->usage,
        ['root_name' => 'customer_usage', 'includes' => ['charges_usage']],
    ))->serialize();

    expect($payload['currency'])->toBe('EUR')
        ->and($payload['lago_invoice_id'])->toBeNull();

    $chargeUsage = $payload['charges_usage']['charges_usage'][0];

    expect($chargeUsage['units'])->toBe('3.0')
        ->and($chargeUsage['events_count'])->toBe(1)
        ->and($chargeUsage['amount_cents'])->toBe(300)
        ->and($chargeUsage['amount_currency'])->toBe('EUR')
        ->and($chargeUsage['charge']['code'])->toBe($result->usage->fees[0]->charge->code)
        ->and($chargeUsage['billable_metric']['code'])->toBe('api_calls')
        ->and($chargeUsage['filters'])->toBe([])
        ->and($chargeUsage['presentation_breakdowns'])->toBe([]);
});

it('fails not_allowed when the subscription is not active', function (): void {
    $metric = usageMetric();
    $customer = usageCustomer($metric);
    $subscription = usageSubscription($customer, ['status' => 'terminated']);

    $result = CustomerUsageService::withExternalIds(
        customerExternalId: $customer->external_id,
        externalSubscriptionId: $subscription->external_id,
        organizationId: $customer->organization_id,
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->code)->toBe('no_active_subscription');
});

it('fails not_found for an unknown customer', function (): void {
    $organization = App\Models\Organization::factory()->create();

    $result = CustomerUsageService::withExternalIds(
        customerExternalId: 'missing',
        externalSubscriptionId: 'sub',
        organizationId: $organization->id,
    );

    expect($result->success())->toBeFalse()
        ->and($result->getError()->resource)->toBe('customer');
});

it('applies the fee and invoice taxes when the customer is taxed', function (): void {
    $metric = usageMetric();
    $customer = usageCustomer($metric);
    $subscription = usageSubscription($customer);
    $charge = usageCharge($metric, $subscription, ['properties' => ['amount' => '1']]);

    $tax = App\Models\Tax::factory()->create([
        'organization_id' => $customer->organization_id,
        'rate' => 20.0,
    ]);

    App\Models\ChargeTax::query()->create([
        'organization_id' => $charge->organization_id,
        'charge_id' => $charge->id,
        'tax_id' => $tax->id,
    ]);

    usageEvent($subscription, now()->utc()->subDays(2)->toIso8601String(), 10);

    $result = CustomerUsageService::withExternalIds(
        customerExternalId: $customer->external_id,
        externalSubscriptionId: $subscription->external_id,
        organizationId: $customer->organization_id,
    );

    $usage = $result->usage;

    expect($usage->amountCents)->toBe(1000)
        ->and($usage->taxesAmountCents)->toBe(200)
        ->and($usage->totalAmountCents)->toBe(1200);
});

it('projects the end-of-period usage when requested', function (): void {
    // Capture the fixture dates against the REAL clock first: the
    // now-relative helpers must not shift once "now" is frozen mid-period.
    $periodStart = usagePeriodStart();
    $eventAt = $periodStart->addDays(3)->addHours(6);
    $midPeriod = $periodStart->addDays(3)->addHours(12);

    Carbon::setTestNow($midPeriod);

    try {
        $metric = usageMetric();
        $customer = usageCustomer($metric);
        $subscription = usageSubscription($customer, [
            'started_at' => $periodStart,
            'subscription_at' => $periodStart,
            'activated_at' => $periodStart,
        ]);
        usageCharge($metric, $subscription, ['properties' => ['amount' => '1']]);

        // Inside the current period, strictly before the frozen "now".
        usageEvent($subscription, $eventAt->toIso8601String(), 10);

        $result = CustomerUsageService::withExternalIds(
            customerExternalId: $customer->external_id,
            externalSubscriptionId: $subscription->external_id,
            organizationId: $customer->organization_id,
            withProjection: true,
        );

        expect($result->usage->projections)->not->toBeNull();

        $payload = (new App\Serializers\V1\Customers\ProjectedUsageSerializer(
            $result->usage,
            ['root_name' => 'customer_projected_usage'],
        ))->serialize();

        // 10 units at mid-period project to ~20 units / ~2000 cents (a
        // standard charge is re-priced on the projected units).
        expect($payload['projected_amount_cents'])->toBeGreaterThan(1500)
            ->and($payload['charges_usage']['charges_usage'][0]['projected_units'])->toBeGreaterThan('15');
    } finally {
        Carbon::setTestNow();
    }
});
