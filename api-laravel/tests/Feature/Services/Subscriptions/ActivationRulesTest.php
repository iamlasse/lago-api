<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Organization;
use App\Services\BaseResult;
use Illuminate\Support\Facades\Queue;
use App\Models\Subscription\ActivationRule;
use App\Models\Subscription\ActivationRule\Payment;
use App\Services\Subscriptions\ActivateService;
use App\Jobs\Subscriptions\ActivationRules\ExpireIncompleteJob;
use App\Jobs\Subscriptions\ActivationRules\Payment\ResolveJob;
use App\Services\Subscriptions\ActivationRules\ApplyService;
use App\Services\Subscriptions\ActivationRules\CancelService;
use App\Services\Subscriptions\ActivationRules\EvaluateService;
use App\Services\Subscriptions\ActivationRules\ExpireService;
use App\Services\Subscriptions\ActivationRules\ValidateService;
use App\Services\Subscriptions\ActivationRules\ResolveSubscriptionStatusService;

/**
 * Port of spec/services/subscriptions/activation_rules/* (core scenarios)
 * and spec/scenarios/subscriptions/payment_gated_activation_spec.rb.
 */
beforeEach(function (): void {
    config(['lago.license' => 'premium-license-token']);
});

function activationRulesOrganization(): Organization
{
    return Organization::factory()->create();
}

function activationRulesCustomer(Organization $organization): \App\Models\Customer
{
    $customer = \App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
        'payment_provider' => 'stripe',
    ]);

    \App\Models\PaymentMethod::factory()
        ->asDefault()
        ->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
        ]);

    return $customer;
}

function gatedSubscription(Organization $organization, array $overrides = []): Subscription
{
    $plan = Plan::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'pay_in_advance' => true,
    ], $overrides['plan'] ?? []));

    $customer = \App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    return Subscription::factory()->pending()->create(array_merge([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'external_id' => 'gated_sub',
        'subscription_at' => now(),
    ], $overrides['subscription'] ?? []));
}

it('applies activation rules on a pending subscription, replacing existing ones', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(subscription: $subscription, activationRules: [['type' => 'payment', 'timeout_hours' => 1]]);
    ApplyService::callBang(subscription: $subscription, activationRules: [['type' => 'payment', 'timeout_hours' => 48]]);

    $rules = $subscription->activationRules()->get();

    expect($rules)->toHaveCount(1)
        ->and($rules[0])->toBeInstanceOf(Payment::class)
        ->and($rules[0]->timeout_hours)->toBe(48)
        ->and($rules[0]->status)->toBe('inactive')
        ->and($rules[0]->type)->toBe('payment');
});

it('refuses to apply activation rules on a non-pending subscription', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);
    $subscription->markAsActive();
    $subscription->save();

    $result = ApplyService::call(subscription: $subscription, activationRules: [['type' => 'payment']]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['activation_rules' => ['subscription_not_pending']]);
});

it('leaves the rules untouched when the params carry none', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    $result = ApplyService::call(subscription: $subscription, activationRules: null);

    expect($result->failure())->toBeFalse()
        ->and($subscription->activationRules()->count())->toBe(0);
});

it('validates the activation rules payload', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    $customer = activationRulesCustomer($organization);

    // Not an array.
    $result = BaseResult::of('activation_rules');
    (new ValidateService($result, [
        'activation_rules' => 'nope',
        'customer' => $customer,
        'subscription' => $subscription,
    ]))->valid();
    expect($result->getError()->messages)->toBe(['activation_rules' => ['invalid_format']]);

    // Unknown type.
    $result = BaseResult::of('activation_rules');
    (new ValidateService($result, [
        'activation_rules' => [['type' => 'manual_approval']],
        'customer' => $customer,
        'subscription' => $subscription,
    ]))->valid();
    expect($result->getError()->messages)->toBe(['activation_rules' => ['invalid_type']]);

    // Duplicated type.
    $result = BaseResult::of('activation_rules');
    (new ValidateService($result, [
        'activation_rules' => [['type' => 'payment'], ['type' => 'payment']],
        'customer' => $customer,
        'subscription' => $subscription,
    ]))->valid();
    expect($result->getError()->messages)->toBe(['activation_rules' => ['duplicated_type']]);

    // Negative timeout.
    $result = BaseResult::of('activation_rules');
    (new ValidateService($result, [
        'activation_rules' => [['type' => 'payment', 'timeout_hours' => -1]],
        'customer' => $customer,
        'subscription' => $subscription,
    ]))->valid();
    expect($result->getError()->messages)->toBe(['timeout_hours' => ['value_must_be_positive_or_zero']]);

    // No payment provider / method on the customer.
    $bareCustomer = \App\Models\Customer::factory()->create(['organization_id' => $organization->id]);

    $result = BaseResult::of('activation_rules');
    (new ValidateService($result, [
        'activation_rules' => [['type' => 'payment', 'timeout_hours' => 1]],
        'customer' => $bareCustomer,
        'subscription' => $subscription,
    ]))->valid();
    // The subscription carries payment_method_type 'provider' (the schema
    // default), so the failure is the missing default method, not the
    // missing provider link.
    expect($result->getError()->messages)->toBe(['customer' => ['no_default_payment_method']]);

    // Happy path.
    $result = BaseResult::of('activation_rules');
    expect((new ValidateService($result, [
        'activation_rules' => [['type' => 'payment', 'timeout_hours' => 1]],
        'customer' => $customer,
        'subscription' => $subscription,
    ]))->valid())->toBeTrue()
        ->and($result->failure())->toBeFalse();
});

it('moves an applicable inactive rule to pending with an expiry, and a non-applicable one to not_applicable', function (): void {
    $organization = activationRulesOrganization();

    // Pay-in-advance plan, not in trial → applicable.
    $subscription = gatedSubscription($organization);
    $rule = Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'timeout_hours' => 24,
    ]);

    EvaluateService::callBang(subscription: $subscription);
    $rule->refresh();

    expect($rule->status)->toBe('pending')
        ->and($rule->expires_at)->not->toBeNull();

    // Trial subscription on a pay-in-advance plan and no pay-in-advance
    // fixed charges → the rule is not applicable.
    $trialSubscription = gatedSubscription($organization, [
        'subscription' => ['started_at' => now()->subDays(1)],
    ]);
    $trialPlan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'pay_in_advance' => true,
        'trial_period' => 10,
    ]);
    $trialSubscription->plan_id = $trialPlan->id;
    $trialSubscription->save();

    $trialRule = Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $trialSubscription->id,
        'timeout_hours' => 24,
    ]);

    EvaluateService::callBang(subscription: $trialSubscription);
    $trialRule->refresh();

    expect($trialRule->status)->toBe('not_applicable')
        ->and($trialRule->expires_at)->toBeNull();
});

it('transitions a pending rule to the given status', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    $rule = Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'status' => 'pending',
    ]);

    \App\Services\Subscriptions\ActivationRules\Payment\EvaluateService::callBang(rule: $rule, status: 'satisfied');
    expect($rule->fresh()->status)->toBe('satisfied');

    // A pending rule without an explicit status raises.
    $pending = Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => gatedSubscription($organization)->id,
        'status' => 'pending',
    ]);

    expect(fn () => \App\Services\Subscriptions\ActivationRules\Payment\EvaluateService::callBang(rule: $pending))
        ->toThrow(InvalidArgumentException::class);
});

it('gates a subscription on activation: incomplete status and a pending rule', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 0]],
    );

    $result = ActivateService::call(subscription: $subscription);
    $subscription->refresh();

    expect($result->failure())->toBeFalse()
        ->and($subscription->incomplete())->toBeTrue()
        ->and($subscription->gated())->toBeTrue()
        ->and($subscription->paymentGated())->toBeTrue()
        ->and($subscription->activationRules()->first()->status)->toBe('pending');
});

it('activates the subscription once the payment rule is satisfied', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 0]],
    );
    ActivateService::callBang(subscription: $subscription);
    $subscription->refresh();
    expect($subscription->incomplete())->toBeTrue();

    // The gating invoice is paid: the rule satisfies and the subscription
    // activates.
    ResolveSubscriptionStatusService::callBang(subscription: $subscription);
    $subscription->refresh();

    expect($subscription->pendingRules())->toBeTrue();

    $rule = $subscription->activationRules()->first();
    \App\Services\Subscriptions\ActivationRules\Payment\EvaluateService::callBang(rule: $rule, status: 'satisfied');

    ResolveSubscriptionStatusService::callBang(subscription: $subscription);
    $subscription->refresh();

    expect($subscription->active())->toBeTrue();
});

it('cancels the subscription when a rule is rejected', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 0]],
    );
    ActivateService::callBang(subscription: $subscription);

    $rule = $subscription->activationRules()->first();
    \App\Services\Subscriptions\ActivationRules\Payment\EvaluateService::callBang(rule: $rule, status: 'failed');

    ResolveSubscriptionStatusService::callBang(subscription: $subscription);
    $subscription->refresh();

    // Rails: mark_as_canceled! only — the reason is stamped by the invoice
    // resolution (see the ResolveService test below).
    expect($subscription->canceled())->toBeTrue();
});

it('resolves the payment rule from the gating invoice outcome', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 0]],
    );
    ActivateService::callBang(subscription: $subscription);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $subscription->customer_id,
        'invoice_type' => InvoiceType::Subscription,
        'status' => InvoiceStatus::Open,
    ]);
    \App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
    ]);

    // Success path: rule satisfied, invoice finalized, subscription active.
    \App\Services\Subscriptions\ActivationRules\Payment\ResolveService::callBang(
        subscription: $subscription,
        invoice: $invoice,
        paymentStatus: 'succeeded',
    );
    $subscription->refresh();
    $invoice->refresh();

    expect($subscription->active())->toBeTrue()
        ->and($subscription->activationRules()->first()->status)->toBe('satisfied')
        ->and($invoice->status)->toBe(InvoiceStatus::Finalized);

    // Failure path on a fresh gated subscription: rule failed, invoice
    // closed, subscription canceled with payment_failed.
    $other = gatedSubscription($organization);
    ApplyService::callBang(
        subscription: $other,
        activationRules: [['type' => 'payment', 'timeout_hours' => 0]],
    );
    ActivateService::callBang(subscription: $other);

    $otherInvoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $other->customer_id,
        'invoice_type' => InvoiceType::Subscription,
        'status' => InvoiceStatus::Open,
    ]);
    \App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $otherInvoice->id,
        'subscription_id' => $other->id,
    ]);

    \App\Services\Subscriptions\ActivationRules\Payment\ResolveService::callBang(
        subscription: $other,
        invoice: $otherInvoice,
        paymentStatus: 'failed',
    );
    $other->refresh();
    $otherInvoice->refresh();

    expect($other->canceled())->toBeTrue()
        ->and($other->cancellation_reason)->toBe('payment_failed')
        ->and($other->activationRules()->first()->status)->toBe('failed')
        ->and($otherInvoice->status)->toBe(InvoiceStatus::Closed);
});

it('expires a timed-out gating subscription and closes the gating invoice', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 1]],
    );
    ActivateService::callBang(subscription: $subscription);
    $subscription->refresh();
    expect($subscription->incomplete())->toBeTrue();

    // The gating invoice (open, subscription type).
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $subscription->customer_id,
        'invoice_type' => InvoiceType::Subscription,
        'status' => InvoiceStatus::Open,
    ]);
    \App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
    ]);

    // The rule timed out.
    $subscription->activationRules()->update(['expires_at' => now()->subHour()]);

    ExpireService::callBang(subscription: $subscription);
    $subscription->refresh();

    expect($subscription->canceled())->toBeTrue()
        ->and($subscription->cancellation_reason)->toBe('timeout')
        ->and($subscription->activationRules()->first()->status)->toBe('expired')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Closed);
});

it('answers subscription_already_resolved when the subscription left the gating window', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);
    $subscription->markAsActive();
    $subscription->save();

    $result = CancelService::call(
        subscription: $subscription,
        ruleStatus: 'expired',
        cancellationReason: 'timeout',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['subscription' => ['subscription_already_resolved']]);
});

it('answers activation_invoice_not_ready when no gating invoice exists', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);
    ApplyService::callBang(subscription: $subscription, activationRules: [['type' => 'payment']]);
    ActivateService::callBang(subscription: $subscription);

    $result = CancelService::call(
        subscription: $subscription,
        ruleStatus: 'declined',
        cancellationReason: 'manual',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['subscription' => ['activation_invoice_not_ready']]);
});

it('hydrates rows as their STI subclass', function (): void {
    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);

    Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
    ]);

    $rule = $subscription->activationRules()->first();

    expect($rule)->toBeInstanceOf(Payment::class)
        ->and($rule->applicable())->toBeTrue()
        ->and(ActivationRule::stiClassFor('payment'))->toBe(Payment::class);
});

it('enqueues the expire and resolve jobs', function (): void {
    Queue::fake();

    $organization = activationRulesOrganization();
    $subscription = gatedSubscription($organization);
    ApplyService::callBang(
        subscription: $subscription,
        activationRules: [['type' => 'payment', 'timeout_hours' => 1]],
    );
    ActivateService::callBang(subscription: $subscription);
    $subscription->activationRules()->update(['expires_at' => now()->subHour()]);

    (new \App\Jobs\Clock\ExpireIncompleteSubscriptionsJob)->handle();

    Queue::assertPushed(ExpireIncompleteJob::class);

    // The ResolveJob handler runs Payment::ResolveService directly (no
    // nested dispatch) — resolve against an open gating invoice.
    $resolveInvoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $subscription->customer_id,
        'invoice_type' => InvoiceType::Subscription,
        'status' => InvoiceStatus::Open,
    ]);
    \App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $resolveInvoice->id,
        'subscription_id' => $subscription->id,
    ]);

    (new ResolveJob($subscription, $resolveInvoice, 'succeeded'))->handle();

    expect($subscription->fresh()->active())->toBeTrue()
        ->and($resolveInvoice->fresh()->status)->toBe(InvoiceStatus::Finalized);
});
