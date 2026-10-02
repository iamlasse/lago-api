<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Support\CurrentContext;
use App\Services\Subscriptions\CreateService;

/**
 * Port of spec/services/subscriptions/create_service_spec.rb (core scenarios).
 */
beforeEach(function (): void {
    CurrentContext::reset();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    CurrentContext::reset();
});

function createPlan(array $overrides = []): Plan
{
    return Plan::factory()->create(array_merge([
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
    ], $overrides));
}

function createServiceCustomer(array $overrides = []): Customer
{
    return Customer::factory()->create(array_merge([
        'currency' => null,
        'external_id' => 'cust_1',
    ], $overrides));
}

it('creates an active subscription when it starts today', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => 'sub_1',
        'name' => '  Main subscription  ',
        'billing_time' => 'calendar',
    ]);

    expect($result->success())->toBeTrue();

    $subscription = $result->subscription;

    expect($subscription->active())->toBeTrue()
        ->and($subscription->external_id)->toBe('sub_1')
        // NOTE: Rails strips the name on create.
        ->and($subscription->name)->toBe('Main subscription')
        ->and($subscription->billing_time)->toBe(0)
        ->and($subscription->started_at->format('Y-m-d'))->toBe('2024-05-15')
        ->and($subscription->activated_at)->not->toBeNull()
        ->and($subscription->consolidate_invoice)->toBeTrue();
})->group('ledger:svc:Subscriptions.CreateService');

it('creates a pending subscription when it starts in the future', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_future',
        'subscription_at' => '2024-06-20T10:00:00Z',
        'billing_time' => 'calendar',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->pending())->toBeTrue()
        ->and($result->subscription->started_at)->toBeNull()
        ->and($result->subscription->subscription_at->format('Y-m-d'))->toBe('2024-06-20');
});

it('creates an active subscription with clamped started_at when it started in the past', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_past',
        'subscription_at' => '2024-01-10T08:30:00Z',
        'billing_time' => 'anniversary',
    ]);

    $subscription = $result->subscription;

    expect($result->success())->toBeTrue()
        ->and($subscription->active())->toBeTrue()
        ->and($subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-01-10 08:30:00');
});

it('clamps a backdated start to the last invoiced termination time', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    Subscription::factory()->terminated()->create([
        'external_id' => 'sub_backdated',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $plan->id,
        'terminated_at' => '2024-03-01 00:00:00',
        'on_termination_invoice' => 'generate',
    ]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_backdated',
        'subscription_at' => '2024-01-01T00:00:00Z',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-03-01 00:00:00');
});

it('does not clamp the backdate when the previous termination did not invoice', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    Subscription::factory()->terminated()->create([
        'external_id' => 'sub_skip',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $plan->id,
        'terminated_at' => '2024-03-01 00:00:00',
        'on_termination_invoice' => 'skip',
    ]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_skip',
        'subscription_at' => '2024-01-01T00:00:00Z',
    ]);

    expect($result->subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-01-01 00:00:00');
});

it('creates the subscription on an existing editable subscription id', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $future = Subscription::factory()->pending()->create([
        'external_id' => 'sub_edit',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $plan->id,
        'subscription_at' => '2024-06-20 00:00:00',
    ]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_edit',
        'subscription_id' => $future->id,
        'billing_time' => 'calendar',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->id)->toBe($future->id);
});

it('routes to the downgrade branch and schedules a pending next subscription', function (): void {
    $customer = createServiceCustomer();
    $currentPlan = createPlan(['amount_cents' => 9900]);
    $cheaperPlan = createPlan(['amount_cents' => 2900, 'code' => 'basic']);

    $current = Subscription::factory()->create([
        'external_id' => 'sub_down',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $currentPlan->id,
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
        'billing_time' => 'calendar',
    ]);

    $result = CreateService::call(customer: $customer, plan: $cheaperPlan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_down',
        'name' => 'Downgraded',
    ]);

    expect($result->success())->toBeTrue();

    // NOTE: Rails' PlanDowngradeService returns the CURRENT subscription in
    // result.subscription; the scheduled downgrade is the pending next one.
    $next = $result->subscription->nextSubscription();

    expect($result->subscription->id)->toBe($current->id)
        ->and($next)->not->toBeNull()
        ->and($next->previous_subscription_id)->toBe($current->id)
        ->and($next->pending())->toBeTrue()
        ->and($next->external_id)->toBe('sub_down')
        ->and($next->name)->toBe('Downgraded')
        ->and($next->billing_time)->toBe($current->billing_time)
        ->and($current->fresh()->active())->toBeTrue();
});

it('fails when the external_id already has an active subscription in the organization', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    // Rails checks org-wide: another customer already holds this external_id.
    $otherCustomer = Customer::factory()->create([
        'organization_id' => $customer->organization_id,
    ]);

    Subscription::factory()->create([
        'external_id' => 'sub_dup',
        'customer_id' => $otherCustomer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $plan->id,
    ]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_dup',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['external_id' => ['value_already_exist']]);
});

it('fails when the subscription is incomplete for the same external_id', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    Subscription::factory()->incomplete()->create([
        'external_id' => 'sub_inc',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $plan->id,
    ]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_inc',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['subscription' => ['subscription_incomplete']]);
});

it('fails in api context without external_customer_id', function (): void {
    CurrentContext::$source = 'api';
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_id' => 'sub_no_cust',
    ]);

    expect($result->getError()->messages)->toBe(['external_customer_id' => ['value_is_mandatory']]);
});

it('fails with an invalid billing_time', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_bt',
        'billing_time' => 'fortnightly',
    ]);

    expect($result->getError()->messages)->toBe(['billing_time' => ['value_is_invalid']]);
});

it('fails with an invalid ending_at', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_ea',
        'ending_at' => '2024-01-01T00:00:00Z', // before subscription_at/today
    ]);

    expect($result->getError()->messages)->toBe(['ending_at' => ['invalid_date']]);
});

it('does not validate or assign on_termination_credit_note on create (Rails parity)', function (): void {
    // Rails' CreateService passes neither on_termination flag to the validator
    // nor to the model on create — only UpdateService/TerminateService set it.
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_otc',
        'on_termination_credit_note' => 'cash',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->on_termination_credit_note)->toBeNull();
});

it('fails with an invalid consolidate_invoice value', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_ci',
        'consolidate_invoice' => 'maybe',
    ]);

    expect($result->getError()->messages)->toBe(['consolidate_invoice' => ['invalid_value']]);
});

it('updates the customer currency from the plan currency', function (): void {
    $customer = createServiceCustomer(['currency' => null]);
    $plan = createPlan(['amount_currency' => 'USD']);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_cur',
    ]);

    expect($result->success())->toBeTrue()
        ->and($customer->fresh()->currency)->toBe('USD');
});

it('accepts ending_at when it is after today and after subscription_at', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan();

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_ok_end',
        'ending_at' => '2025-01-01T00:00:00Z',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->ending_at->format('Y-m-d'))->toBe('2025-01-01');
});

it('stores progressive billing settings and normalizes the purchase order number', function (): void {
    $customer = createServiceCustomer();
    $plan = createPlan(['pay_in_advance' => true]);

    $result = CreateService::call(customer: $customer, plan: $plan, params: [
        'external_customer_id' => $customer->external_id,
        'external_id' => 'sub_flags',
        'progressive_billing_disabled' => true,
        'purchase_order_number' => '  PO-99  ',
    ]);

    $subscription = $result->subscription;

    // NOTE: Rails' CreateService validates but does NOT assign
    // on_termination_credit_note on create (it is set through UpdateService /
    // TerminateService for pay-in-advance plans) — mirrored here.
    expect($subscription->on_termination_credit_note)->toBeNull()
        ->and($subscription->progressive_billing_disabled)->toBeTrue()
        // Rails normalizes the PO number on assignment.
        ->and($subscription->purchase_order_number)->toBe('PO-99');
});
