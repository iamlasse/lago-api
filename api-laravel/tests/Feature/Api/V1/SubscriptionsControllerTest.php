<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/subscriptions',
    'ledger:rest:GET:/api/v1/subscriptions',
    'ledger:rest:GET:/api/v1/subscriptions/:external_id',
    'ledger:rest:PUT:/api/v1/subscriptions/:external_id',
    'ledger:rest:PATCH:/api/v1/subscriptions/:external_id',
    'ledger:rest:PATCH:/api/v2/subscriptions/:external_id',
    'ledger:rest:DELETE:/api/v1/subscriptions/:external_id',
);

use App\Models\Plan;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\Subscription;

/**
 * Port of Rails' spec/requests/api/v1/subscriptions_controller_spec.rb
 * (plus the "a subscription index endpoint" shared example).
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - payment pre-authorization success paths (Stripe integration);
 * - plan_overrides persistence (premium override service) — the non-premium
 *   forbidden gate is covered by the CreateService tests;
 * - invoice custom sections / entitlements / usage thresholds payloads
 *   (serializer TODOs — emitted empty);
 * - connections / activation_rules persistence (services TODO'd).
 */
function subscriptionOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function subscriptionGetWithToken(string $path, array $params = [], ?string $token = null): Illuminate\Testing\TestResponse
{
    $url = $params === [] ? $path : $path.'?'.http_build_query($params);

    return test()->getJson($url, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

/**
 * The factory's organization_id resolver cannot see `for()` parents (they
 * are flattened to keys), so the ids are passed explicitly.
 */
function makeSubscription(Customer $customer, Plan $plan, array $attributes = []): Subscription
{
    return Subscription::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'organization_id' => $customer->organization_id,
    ], $attributes));
}

function makePendingSubscription(Customer $customer, Plan $plan, array $attributes = []): Subscription
{
    return makeSubscription($customer, $plan, array_merge([
        'status' => 'pending',
        'started_at' => null,
        'activated_at' => null,
    ], $attributes));
}

function makeTerminatedSubscription(Customer $customer, Plan $plan, array $attributes = []): Subscription
{
    return makeSubscription($customer, $plan, array_merge([
        'status' => 'terminated',
        'started_at' => now()->subMonth(),
        'activated_at' => now()->subMonth(),
        'terminated_at' => now(),
    ], $attributes));
}

// -- POST /api/v1/subscriptions --------------------------------------------------

it('creates a subscription', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();
    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 500,
    ]);

    $externalId = (string) Str::uuid();

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'name' => 'subscription name',
        'external_id' => $externalId,
        'billing_time' => 'anniversary',
        'subscription_at' => '2024-06-05T12:23:12Z',
        'ending_at' => '2027-06-05T00:00:00Z',
        'purchase_order_number' => 'PO-123',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer, $plan, $externalId) {
            $json->where('subscription.lago_id', fn ($id) => is_string($id) && $id !== '')
                ->where('subscription.external_id', $externalId)
                ->where('subscription.external_customer_id', $customer->external_id)
                ->where('subscription.lago_customer_id', $customer->id)
                ->where('subscription.plan_code', $plan->code)
                ->where('subscription.plan_amount_cents', 500)
                ->where('subscription.plan_amount_currency', $plan->amount_currency)
                ->where('subscription.status', 'active')
                ->where('subscription.name', 'subscription name')
                ->where('subscription.started_at', fn ($at) => is_string($at) && $at !== '')
                ->where('subscription.billing_time', 'anniversary')
                ->where('subscription.subscription_at', '2024-06-05T12:23:12Z')
                ->where('subscription.ending_at', '2027-06-05T00:00:00Z')
                ->where('subscription.purchase_order_number', 'PO-123')
                ->where('subscription.previous_plan_code', null)
                ->where('subscription.next_plan_code', null)
                ->where('subscription.downgrade_plan_date', null)
                ->where('subscription.entitlements', [])
                ->where('subscription.applicable_usage_thresholds', [])
                ->where('subscription.applied_invoice_custom_sections', [])
                ->where('subscription.plan.lago_id', (string) $plan->id)
                ->etc();
        });
});

it('does not create a new customer when it exists', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])->assertOk();

    expect(Customer::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

it('accepts external_customer_id, name and external_id as integers', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => 123,
        'plan_code' => $plan->code,
        'name' => 456,
        'external_id' => 789,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->where('subscription.lago_id', fn ($id) => is_string($id) && $id !== '')
                ->where('subscription.external_customer_id', '123')
                ->where('subscription.name', '456')
                ->where('subscription.external_id', '789')
                ->etc();
        });

    $customer = Customer::query()->where('external_id', '123')->first();
    expect($customer->organization_id)->toBe($organization->id)
        ->and($customer->billing_entity_id)->toBe($organization->defaultBillingEntity->id);
});

it('creates a new customer in the given billing entity', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $billingEntity = App\Models\BillingEntity::factory()->for($organization)->create();

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => 123,
        'plan_code' => $plan->code,
        'name' => 456,
        'external_id' => 789,
        'billing_entity_code' => $billingEntity->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])->assertOk();

    $customer = Customer::query()->where('external_id', '123')->first();
    expect($customer->billing_entity_id)->toBe($billingEntity->id);
});

it('returns not_found when the billing entity does not exist', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => 123,
        'plan_code' => $plan->code,
        'name' => 456,
        'external_id' => 789,
        'billing_entity_code' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'billing_entity_not_found');
});

it('returns an unprocessable_entity error without external_customer_id', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'plan_code' => $plan->code,
        'name' => 'subscription name',
        'external_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(422)
        ->assertJsonPath('error_details.external_customer_id.0', 'value_is_mandatory');
});

it('returns not_found with an invalid plan code', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code.'-invalid',
        'external_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'plan_not_found');
});

it('returns an unprocessable_entity error with an invalid subscription_at', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => (string) Str::uuid(),
        'subscription_at' => 'hello',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])->assertStatus(422);
});

it('forbids payment pre-authorization when the feature is not enabled', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/subscriptions', [
        'authorization' => ['amount_cents' => '100', 'amount_currency' => 'USD'],
        'subscription' => [
            'external_customer_id' => $customer->external_id,
            'plan_code' => $plan->code,
            'external_id' => (string) Str::uuid(),
        ],
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(403)
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->where('status', 403)
                ->where('error', 'Forbidden')
                ->where('code', 'feature_not_available')
                ->where('message', fn ($message) => str_contains((string) $message, 'beta_payment_authorization'));
        });
});

it('creates a subscription with progressive_billing_disabled', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $externalId = (string) Str::uuid();

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => $externalId,
        'progressive_billing_disabled' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.progressive_billing_disabled', true);

    expect(Subscription::query()->where('external_id', $externalId)->first()->progressive_billing_disabled)->toBeTrue();
});

// -- DELETE /api/v1/subscriptions/:external_id -------------------------------------

it('terminates a subscription without deleting it', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->where('subscription.lago_id', (string) $subscription->id)
                ->where('subscription.status', 'terminated')
                ->where('subscription.terminated_at', fn ($at) => is_string($at) && $at !== '')
                ->where('subscription.on_termination_credit_note', null)
                ->where('subscription.on_termination_invoice', 'generate')
                ->etc();
        });

    // NOTE: the row still exists — terminate, never destroy.
    $terminated = $subscription->fresh();
    expect($terminated->statusName())->toBe('terminated')
        ->and($terminated->terminated_at)->not->toBeNull();
});

it('terminates a subscription whose external_id contains dots', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan, ['external_id' => 'coker.com']);

    $this->deleteJson('/api/v1/subscriptions/coker.com', [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertOk()
        ->assertJsonPath('subscription.external_id', 'coker.com')
        ->assertJsonPath('subscription.status', 'terminated');
});

it('ignores on_termination_credit_note when the plan is pay in arrears', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_credit_note' => 'credit',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.on_termination_credit_note', null);
});

it('keeps the default credit behaviour for a pay in advance plan', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->payInAdvance()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('subscription.on_termination_credit_note', 'credit');
});

it('terminates a pay in advance subscription with skip credit note behaviour', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->payInAdvance()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_credit_note' => 'skip',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.on_termination_credit_note', 'skip');

    expect($subscription->fresh()->on_termination_credit_note)->toBe('skip');
});

it('terminates a pay in advance subscription with refund credit note behaviour', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->payInAdvance()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_credit_note' => 'refund',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.on_termination_credit_note', 'refund');
});

it('returns a validation error with an invalid on_termination_credit_note', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->payInAdvance()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_credit_note' => 'invalid',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(422)
        ->assertJsonPath('error_details.on_termination_credit_note.0', 'invalid_value');
});

it('terminates with skip invoice behaviour', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_invoice' => 'skip',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.on_termination_invoice', 'skip');

    expect($subscription->fresh()->on_termination_invoice)->toBe('skip');
});

it('returns a validation error with an invalid on_termination_invoice', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_invoice' => 'invalid',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(422)
        ->assertJsonPath('error_details.on_termination_invoice.0', 'invalid_value');
});

it('terminates with both on_termination behaviours', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->payInAdvance()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'on_termination_credit_note' => 'skip',
        'on_termination_invoice' => 'skip',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.on_termination_credit_note', 'skip')
        ->assertJsonPath('subscription.on_termination_invoice', 'skip');
});

it('returns not_found when terminating a pending subscription without status', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

it('cancels a pending subscription when status is given', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'status' => 'pending',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->where('subscription.lago_id', (string) $subscription->id)
                ->where('subscription.status', 'canceled')
                ->where('subscription.canceled_at', fn ($at) => is_string($at) && $at !== '')
                ->etc();
        });

    expect($subscription->fresh()->statusName())->toBe('canceled');
});

it('cancels an incomplete subscription when status is given', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan, [
        'status' => 'incomplete',
        'started_at' => now(),
        'activated_at' => null,
    ]);

    // A real incomplete subscription is a payment-gated one: it carries a
    // pending activation rule and an open gating invoice — both are needed
    // for the cancellation to run (Rails: ActivationRules::CancelService).
    Subscription\ActivationRule\Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
    ]);

    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'invoice_type' => App\Enums\InvoiceType::Subscription,
        'status' => App\Enums\InvoiceStatus::Open,
    ]);
    App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
    ]);

    $this->deleteJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'status' => 'incomplete',
    ], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.status', 'canceled')
        ->assertJsonPath('subscription.cancellation_reason', 'manual');

    expect($subscription->fresh()->statusName())->toBe('canceled')
        ->and($invoice->fresh()->status)->toBe(App\Enums\InvoiceStatus::Closed)
        ->and($subscription->fresh()->activationRules()->first()->status)->toBe('declined');
});

it('returns not_found when the terminated subscription does not exist', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();

    $this->deleteJson('/api/v1/subscriptions/'.(string) Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('code', 'subscription_not_found');
});

// -- PUT /api/v1/subscriptions/:external_id ------------------------------------------

it('updates a subscription', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan);

    $this->putJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'name' => 'subscription name new',
        'subscription_at' => '2022-09-05T12:23:12Z',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->where('subscription.lago_id', (string) $subscription->id)
                ->where('subscription.name', 'subscription name new')
                ->where('subscription.subscription_at', '2022-09-05T12:23:12Z')
                ->etc();
        });

    expect($subscription->fresh()->name)->toBe('subscription name new');
});

it('updates a subscription whose external_id contains dots', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan, ['external_id' => 'coker.com']);

    $this->putJson('/api/v1/subscriptions/coker.com', ['subscription' => [
        'name' => 'subscription name new',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.external_id', 'coker.com')
        ->assertJsonPath('subscription.name', 'subscription name new');
});

it('updates progressive_billing_disabled', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->putJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'progressive_billing_disabled' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.progressive_billing_disabled', true);

    expect($subscription->fresh()->progressive_billing_disabled)->toBeTrue();
});

it('updates consolidate_invoice', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->putJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'consolidate_invoice' => false,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.consolidate_invoice', false);

    expect($subscription->fresh()->consolidate_invoice)->toBeFalse();
});

it('updates purchase_order_number', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->putJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'purchase_order_number' => 'PO-123',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.purchase_order_number', 'PO-123');

    expect($subscription->fresh()->purchase_order_number)->toBe('PO-123');
});

it('updates the active subscription when there are multiple', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $pending = makePendingSubscription($customer, $plan);
    $active = makeSubscription($customer, $plan, ['external_id' => $pending->external_id]);

    $this->putJson('/api/v1/subscriptions/'.$pending->external_id, ['subscription' => [
        'name' => 'subscription name new',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.lago_id', (string) $active->id)
        ->assertJsonPath('subscription.name', 'subscription name new');
});

it('updates the pending subscription when status is given', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $pending = makePendingSubscription($customer, $plan);
    makeSubscription($customer, $plan, ['external_id' => $pending->external_id]);

    $this->putJson('/api/v1/subscriptions/'.$pending->external_id.'?status=pending', ['subscription' => [
        'name' => 'subscription name new',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.lago_id', (string) $pending->id)
        ->assertJsonPath('subscription.name', 'subscription name new');

    expect($pending->fresh()->statusName())->toBe('pending');
});

it('returns method_not_allowed when updating an incomplete subscription', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan, [
        'status' => 'incomplete',
        'started_at' => now(),
        'activated_at' => null,
    ]);

    $this->putJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'name' => 'new name',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertStatus(405)
        ->assertJsonPath('code', 'subscription_incomplete');
});

it('returns not_found when the updated subscription does not exist', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();

    $this->putJson('/api/v1/subscriptions/'.(string) Str::uuid(), ['subscription' => [
        'name' => 'new name',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound()
        ->assertJsonPath('code', 'subscription_not_found');
});

// -- PATCH /api/v1/subscriptions/:external_id -----------------------------------------
// Rails routes PATCH and PUT to the same SubscriptionsController#update
// (resources :subscriptions draws both verbs; no PATCH-specific branch
// exists), so the scenarios below port the PUT section's expectations to the
// PATCH verb.

it('updates a subscription via PATCH', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan);

    $this->patchJson('/api/v1/subscriptions/'.$subscription->external_id, ['subscription' => [
        'name' => 'subscription name new',
        'subscription_at' => '2022-09-05T12:23:12Z',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->where('subscription.lago_id', (string) $subscription->id)
                ->where('subscription.name', 'subscription name new')
                ->where('subscription.subscription_at', '2022-09-05T12:23:12Z')
                ->etc();
        });

    expect($subscription->fresh()->name)->toBe('subscription name new');
});

it('updates a subscription whose external_id contains dots via PATCH', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan, ['external_id' => 'coker.com']);

    $this->patchJson('/api/v1/subscriptions/coker.com', ['subscription' => [
        'name' => 'subscription name new',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('subscription.external_id', 'coker.com')
        ->assertJsonPath('subscription.name', 'subscription name new');
});

it('mirrors the subscription update via PATCH at v2 with the beta header', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makePendingSubscription($customer, $plan);

    $this->patchJson('/api/v2/subscriptions/'.$subscription->external_id, ['subscription' => [
        'name' => 'subscription name new',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('subscription.lago_id', (string) $subscription->id)
        ->assertJsonPath('subscription.name', 'subscription name new');
});

// -- GET /api/v1/subscriptions/:external_id ------------------------------------------

it('shows a subscription', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $this->getJson('/api/v1/subscriptions/'.$subscription->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->where('subscription.lago_id', (string) $subscription->id)
                ->where('subscription.external_id', $subscription->external_id)
                ->where('subscription.status', 'active')
                ->where('subscription.entitlements', [])
                ->where('subscription.applicable_usage_thresholds', [])
                ->etc();
        });
});

it('shows a subscription whose external_id contains dots', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan, ['external_id' => 'coker.com']);

    $this->getJson('/api/v1/subscriptions/coker.com', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertOk()
        ->assertJsonPath('subscription.lago_id', (string) $subscription->id)
        ->assertJsonPath('subscription.external_id', 'coker.com');
});

it('returns not_found when the shown subscription does not exist', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();

    $this->getJson('/api/v1/subscriptions/'.(string) Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('code', 'subscription_not_found');
});

it('shows the subscription matching the given status', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $active = makeSubscription($customer, $plan);
    $pending = makePendingSubscription($customer, $plan, ['external_id' => $active->external_id]);

    subscriptionGetWithToken('/api/v1/subscriptions/'.$active->external_id, ['status' => 'pending'], $apiKey->value)
        ->assertOk()
        ->assertJsonPath('subscription.lago_id', (string) $pending->id);
});

it('shows the latest terminated subscription', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $oldest = makeTerminatedSubscription($customer, $plan, ['terminated_at' => now()->subDays(10)]);
    $latest = makeTerminatedSubscription($customer, $plan, [
        'external_id' => $oldest->external_id,
        'terminated_at' => now()->subDays(5),
    ]);

    subscriptionGetWithToken('/api/v1/subscriptions/'.$oldest->external_id, ['status' => 'terminated'], $apiKey->value)
        ->assertOk()
        ->assertJsonPath('subscription.lago_id', (string) $latest->id);
});

// -- GET /api/v1/subscriptions (index) -------------------------------------------------

it('lists subscriptions', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    subscriptionGetWithToken('/api/v1/subscriptions', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $subscription->id)
                ->etc();
        });
});

it('lists subscriptions of the given external customer', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeSubscription($customer, $plan);
    $customer2 = Customer::factory()->forOrganization($organization)->create();
    $subscription2 = makeSubscription($customer2, $plan);

    subscriptionGetWithToken('/api/v1/subscriptions', [
        'external_customer_id' => $customer2->external_id,
    ], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription2) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $subscription2->id)
                ->etc();
        });
});

it('paginates subscriptions with meta data', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $anotherPlan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 30000]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeSubscription($customer, $plan);
    makeSubscription($customer, $anotherPlan);

    subscriptionGetWithToken('/api/v1/subscriptions', ['page' => 1, 'per_page' => 1], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) {
            $json->count('subscriptions', 1)
                ->where('meta.current_page', 1)
                ->where('meta.next_page', 2)
                ->where('meta.prev_page', null)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2)
                ->etc();
        });
});

it('filters subscriptions by plan code', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);
    makeSubscription($customer, Plan::factory()->create(['organization_id' => $organization->id]));

    subscriptionGetWithToken('/api/v1/subscriptions', ['plan_code' => $plan->code], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $subscription->id)
                ->etc();
        });
});

it('filters subscriptions by the overriden legacy spelling', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);
    $overriddenPlan = Plan::factory()->create(['organization_id' => $organization->id, 'parent_id' => $plan->id]);
    $overriddenSubscription = makeSubscription($customer, $overriddenPlan);

    // overriden: true — overridden (child) plans only.
    subscriptionGetWithToken('/api/v1/subscriptions', ['overriden' => 'true'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($overriddenSubscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $overriddenSubscription->id)
                ->etc();
        });

    // overriden: false — parent plans only.
    subscriptionGetWithToken('/api/v1/subscriptions', ['overriden' => 'false'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($subscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $subscription->id)
                ->etc();
        });

    // overridden (correct spelling) wins.
    subscriptionGetWithToken('/api/v1/subscriptions', ['overridden' => 'true'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($overriddenSubscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $overriddenSubscription->id)
                ->etc();
        });
});

it('filters subscriptions by currency', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $brlPlan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'BRL']);
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeSubscription($customer, $plan);
    $brlSubscription = makeSubscription($customer, $brlPlan);

    subscriptionGetWithToken('/api/v1/subscriptions', ['currency' => 'BRL'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($brlSubscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $brlSubscription->id)
                ->etc();
        });
});

it('filters subscriptions by external_id and status', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $externalId = (string) Str::uuid();
    $active = makeSubscription($customer, $plan, ['external_id' => $externalId]);
    $terminated = makeTerminatedSubscription($customer, $plan, ['external_id' => $externalId]);

    subscriptionGetWithToken('/api/v1/subscriptions', [
        'external_id' => $externalId,
        'status[0]' => 'active',
        'status[1]' => 'terminated',
    ], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($active, $terminated) {
            $json->count('subscriptions', 2)
                ->where('subscriptions.0.lago_id', fn ($id) => in_array($id, [(string) $active->id, (string) $terminated->id], true))
                ->etc();
        });
});

it('filters subscriptions by billing entity codes', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $euEntity = App\Models\BillingEntity::factory()->for($organization)->create(['code' => 'eu']);
    $usEntity = App\Models\BillingEntity::factory()->for($organization)->create(['code' => 'us']);
    $euSubscription = makeSubscription($customer, $plan, ['billing_entity_id' => $euEntity->id]);
    makeSubscription($customer, $plan, ['billing_entity_id' => $usEntity->id]);

    // Single code.
    subscriptionGetWithToken('/api/v1/subscriptions', ['billing_entity_codes[0]' => 'eu'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($euSubscription) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $euSubscription->id)
                ->etc();
        });

    // Multiple codes.
    subscriptionGetWithToken('/api/v1/subscriptions', [
        'billing_entity_codes[0]' => 'eu',
        'billing_entity_codes[1]' => 'us',
    ], $apiKey->value)
        ->assertOk()
        ->assertJsonPath('meta.total_count', 2);

    // Unknown code — not found envelope.
    subscriptionGetWithToken('/api/v1/subscriptions', ['billing_entity_codes[0]' => 'unknown'], $apiKey->value)
        ->assertNotFound()
        ->assertJsonPath('code', 'billing_entity_not_found');
});

it('filters subscriptions by terminated status', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    makeSubscription($customer, $plan);
    $terminated = makeTerminatedSubscription($customer, $plan);

    subscriptionGetWithToken('/api/v1/subscriptions', ['status[0]' => 'terminated'], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($terminated) {
            $json->count('subscriptions', 1)
                ->where('subscriptions.0.lago_id', (string) $terminated->id)
                ->etc();
        });
});

it('lists next and previous plan codes', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $previousPlan = Plan::factory()->create(['organization_id' => $organization->id]);
    $nextPlan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $subscription = makeSubscription($customer, $plan);

    $previous = makeTerminatedSubscription($customer, $previousPlan);
    $next = makePendingSubscription($customer, $nextPlan, ['previous_subscription_id' => $subscription->id]);
    $subscription->previous_subscription_id = $previous->id;
    $subscription->save();

    subscriptionGetWithToken('/api/v1/subscriptions', [], $apiKey->value)
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($previousPlan, $nextPlan) {
            $json->where('subscriptions.0.previous_plan_code', $previousPlan->code)
                ->where('subscriptions.0.next_plan_code', $nextPlan->code)
                ->etc();
        });
});

// -- permissions ---------------------------------------------------------------------

it('requires an api permission to write subscriptions', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = subscriptionOrganization();

    $customer = Customer::factory()->forOrganization($organization)->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['subscription' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => (string) Str::uuid(),
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'write_action_not_allowed_for_subscription');
});

it('requires an api permission to read subscriptions', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = subscriptionOrganization();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['subscription' => ['write']]), $apiKey->id],
    );

    subscriptionGetWithToken('/api/v1/subscriptions', [], $apiKey->value)
        ->assertForbidden()
        ->assertJsonPath('code', 'read_action_not_allowed_for_subscription');
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the subscriptions endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = subscriptionOrganization();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->forOrganization($organization)->create();
    $externalId = (string) Str::uuid();

    $this->postJson('/api/v2/subscriptions', ['subscription' => [
        'external_customer_id' => $customer->external_id,
        'plan_code' => $plan->code,
        'external_id' => $externalId,
        'name' => 'V2 Subscription',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('subscription.name', 'V2 Subscription');

    subscriptionGetWithToken('/api/v2/subscriptions', [], $apiKey->value)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);

    $this->getJson('/api/v2/subscriptions/'.$externalId, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('subscription.external_id', $externalId);

    $this->deleteJson('/api/v2/subscriptions/'.$externalId, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('subscription.status', 'terminated');

    // Errors carry the beta header too.
    $this->getJson('/api/v2/subscriptions/'.(string) Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});
