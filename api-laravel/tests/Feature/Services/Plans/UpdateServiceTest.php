<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Enums\PlanInterval;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Services\Plans\UpdateService;
use App\Services\Failures\NotFoundFailure;

/**
 * Port of spec/services/plans/update_service_spec.rb (core scenarios).
 */
function updatablePlan(array $attributes = []): Plan
{
    return Plan::factory()->create($attributes);
}

it('updates the editable attributes of a plan', function (): void {
    $plan = updatablePlan();

    $result = UpdateService::call(plan: $plan, params: [
        'name' => 'Renamed',
        'description' => 'New description',
        'amount_cents' => 200,
        'code' => 'new-code',
        'interval' => 'yearly',
        'pay_in_advance' => true,
        'amount_currency' => 'USD',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->plan->name)->toBe('Renamed')
        ->and($result->plan->description)->toBe('New description')
        ->and($result->plan->amount_cents)->toBe(200)
        ->and($result->plan->code)->toBe('new-code')
        ->and($result->plan->interval)->toBe(PlanInterval::Yearly->value)
        ->and($result->plan->pay_in_advance)->toBeTrue()
        ->and($result->plan->amount_currency)->toBe('USD');
})->group('ledger:svc:Plans.UpdateService');

it('only allows the editable attributes when attached to a subscription', function (): void {
    $plan = updatablePlan();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $plan->organization_id]);

    Subscription::query()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'status' => 1, // active
        'external_id' => 'sub-1',
        'billing_time' => 0,
        'name' => 'sub',
    ]);

    $result = UpdateService::call(plan: $plan, params: [
        'name' => 'Renamed',
        'code' => 'should-not-change',
        'interval' => 'yearly',
        'amount_currency' => 'USD',
        'amount_cents' => 200,
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->plan->name)->toBe('Renamed')
        ->and($result->plan->amount_cents)->toBe(200)
        ->and($result->plan->code)->toBe($plan->code)
        ->and($result->plan->interval)->toBe(PlanInterval::Monthly->value)
        ->and($result->plan->amount_currency)->toBe('EUR');
});

it('fails when the plan is missing', function (): void {
    $result = UpdateService::call(plan: null, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('creates, updates and destroys charges through the nested payload', function (): void {
    $plan = updatablePlan();
    $metricA = BillableMetric::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'metric-a']);
    $metricB = BillableMetric::factory()->create(['organization_id' => $plan->organization_id, 'code' => 'metric-b']);

    $kept = Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metricA->id,
        'charge_model' => 'standard',
        'code' => 'kept',
        'properties' => ['amount' => '10'],
    ]);

    $removed = Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metricB->id,
        'charge_model' => 'standard',
        'code' => 'removed',
        'properties' => ['amount' => '10'],
    ]);

    $result = UpdateService::call(plan: $plan, params: [
        'charges' => [
            // update kept, create a new one; $removed is absent -> destroyed
            ['id' => $kept->id, 'billable_metric_id' => $metricA->id, 'charge_model' => 'standard', 'code' => 'kept', 'properties' => ['amount' => '55']],
            ['billable_metric_id' => $metricB->id, 'charge_model' => 'standard', 'code' => 'added', 'properties' => ['amount' => '7']],
        ],
    ]);

    expect($result->success())->toBeTrue();

    $charges = $plan->charges()->withTrashed()->get()->keyBy('code');

    expect($charges->has('kept'))->toBeTrue()
        ->and($charges['kept']->properties)->toBe(['amount' => '55'])
        ->and($charges->has('added'))->toBeTrue()
        ->and($charges->has('removed'))->toBeTrue()
        ->and($charges['removed']->deleted_at)->not->toBeNull();
});

it('fails when nested charges reference an unknown billable metric', function (): void {
    $plan = updatablePlan();

    $result = UpdateService::call(plan: $plan, params: [
        'charges' => [
            ['billable_metric_id' => '00000000-0000-0000-0000-000000000000', 'charge_model' => 'standard', 'code' => 'x', 'properties' => ['amount' => '1']],
        ],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('creates and destroys fixed charges through the nested payload', function (): void {
    $plan = updatablePlan();
    $addOn = App\Models\AddOn::factory()->create(['organization_id' => $plan->organization_id]);
    $otherAddOn = App\Models\AddOn::factory()->create(['organization_id' => $plan->organization_id]);

    $kept = App\Models\FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'add_on_id' => $addOn->id,
        'code' => 'kept-fc',
        'charge_model' => 'standard',
        'units' => 1,
        'properties' => ['amount' => '100'],
    ]);

    $result = UpdateService::call(plan: $plan, params: [
        'fixed_charges' => [
            ['id' => $kept->id, 'add_on_id' => $addOn->id, 'charge_model' => 'standard', 'code' => 'kept-fc', 'units' => 5, 'properties' => ['amount' => '100']],
            ['add_on_id' => $otherAddOn->id, 'charge_model' => 'standard', 'units' => 2, 'properties' => ['amount' => '200']],
        ],
    ]);

    expect($result->success())->toBeTrue();

    $fixedCharges = $plan->fixedCharges()->withTrashed()->get()->keyBy('code');

    expect($fixedCharges['kept-fc']->units)->toBe('5.0000000000')
        ->and($fixedCharges)->toHaveCount(2)
        ->and($fixedCharges->keys()->reject(fn ($code) => $code === 'kept-fc')->values()[0])->not->toBeNull();

    $destroyed = $plan->fixedCharges()->onlyTrashed()->get();
    expect($destroyed)->toHaveCount(0);
});

it('cancels a pending downgrade subscription when the plan amount decreases below it', function (): void {
    $plan = updatablePlan(['amount_cents' => 200]);
    $customer = App\Models\Customer::factory()->create(['organization_id' => $plan->organization_id]);

    $previousSubscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'status' => 1, // active
        'external_id' => 'prev',
        'billing_time' => 0,
        'name' => 'prev',
    ]);

    // A pending subscription on the (more expensive) plan the customer
    // planned to downgrade to.
    $downgradeTarget = updatablePlan(['amount_cents' => 999]);
    $pending = Subscription::query()->create([
        'customer_id' => $customer->id,
        'plan_id' => $downgradeTarget->id,
        'organization_id' => $plan->organization_id,
        'status' => 0, // pending
        'external_id' => 'pending',
        'previous_subscription_id' => $previousSubscription->id,
        'billing_time' => 0,
        'name' => 'pending',
    ]);

    // Downgrading plan A to 50 (< the 999 downgrade target) makes the pending
    // subscription irrelevant — Rails marks it canceled.
    $result = UpdateService::call(plan: $plan, params: ['amount_cents' => 50]);

    expect($result->success())->toBeTrue();

    $pending->refresh();

    expect($pending->status)->toBe(3) // canceled
        ->and($pending->canceled_at)->not->toBeNull();
});

it('flags the organization draft invoices attached to the plan for refresh', function (): void {
    $plan = updatablePlan();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $plan->organization_id]);

    $subscription = Subscription::query()->create([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'status' => 1,
        'external_id' => 'sub',
        'billing_time' => 0,
        'name' => 'sub',
    ]);

    $invoice = App\Models\Invoice::query()->create([
        'organization_id' => $plan->organization_id,
        'customer_id' => $customer->id,
        'status' => 0, // draft
        'invoice_type' => 0,
        'currency' => 'EUR',
        'billing_entity_id' => $customer->billing_entity_id,
    ]);

    Illuminate\Support\Facades\DB::table('invoice_subscriptions')->insert([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $plan->organization_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otherInvoice = App\Models\Invoice::query()->create([
        'organization_id' => $plan->organization_id,
        'customer_id' => $customer->id,
        'status' => 1, // finalized — untouched
        'invoice_type' => 0,
        'currency' => 'EUR',
        'billing_entity_id' => $customer->billing_entity_id,
    ]);

    $result = UpdateService::call(plan: $plan, params: ['amount_cents' => $plan->amount_cents + 1]);

    expect($result->success())->toBeTrue();

    $invoice->refresh();
    $otherInvoice->refresh();

    expect($invoice->ready_to_be_refreshed)->toBeTrue()
        ->and($otherInvoice->ready_to_be_refreshed)->toBeFalse();
});
