<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Wallet;
use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\Subscription;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\QuoteVersion;
use App\Support\CurrentContext;
use App\Services\Orders\ExecuteService;
use App\Enums\OrderExecutionMode;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

/**
 * Port of spec/services/orders/subscription_creation & _amendment specs
 * (core scenarios) — the two subscription branches of the order executor.
 */
beforeEach(function (): void {
    CurrentContext::reset();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function subscriptionOrderOrganization(bool $flag = true): Organization
{
    $organization = Organization::factory()->create(
        $flag ? ['feature_flags' => ['order_forms']] : [],
    );

    return CurrentContext::$organization = $organization;
}

/**
 * The spec chain: signed order form over an approved quote version, the
 * order hanging off it. The billing items snapshot carries a plan item with
 * overrides, one coupon and one wallet credit.
 */
function subscriptionOrder(Organization $organization, string $orderType, array $billingItems, array $orderAttributes = []): Order
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $quote = Quote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_type' => $orderType,
    ]);

    $quoteVersion = QuoteVersion::factory()->create([
        'organization_id' => $organization->id,
        'quote_id' => $quote->id,
        'status' => 'approved',
        'approved_at' => now(),
        'currency' => 'EUR',
        'billing_items' => $billingItems,
    ]);

    $orderForm = OrderForm::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'quote_version_id' => $quoteVersion->id,
        'status' => 'signed',
        'signed_at' => now(),
    ]);

    return Order::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_form_id' => $orderForm->id,
        'status' => 'created',
        'execution_mode' => 'execute_in_lago',
    ], $orderAttributes));
}

it('executes a subscription_creation order: subscription, coupon and wallet', function (): void {
    $organization = subscriptionOrderOrganization();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
    ]);

    $charge = \App\Models\Charge::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'properties' => ['amount' => '10'],
    ]);

    $coupon = \App\Models\Coupon::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $customer = $order = null;

    $subscriptionExternalId = (string) Str::uuid();

    $order = subscriptionOrder($organization, Quote::ORDER_TYPES['subscription_creation'], [
        'plans' => [[
            'id' => $plan->id,
            'localId' => $subscriptionExternalId,
            'payload' => [
                'charges' => [[
                    'id' => $charge->id,
                    'chargeModel' => 'standard',
                    'billableMetric' => ['code' => $charge->billable_metric_id],
                ]],
            ],
            'overrides' => [
                'amountCents' => 9900,
                'name' => 'Negotiated plan',
                'charges' => [
                    [
                        'billableMetricCode' => $charge->billable_metric_id,
                        'invoiceDisplayName' => 'Negotiated charge',
                    ],
                ],
            ],
        ]],
        'coupons' => [
            ['id' => $coupon->id, 'payload' => ['frequency' => 'once']],
        ],
        'walletCredits' => [
            ['payload' => ['rateAmount' => '1', 'paidCredits' => '10', 'grantedCredits' => '0']],
        ],
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->failure())->toBeFalse()
        ->and($order->fresh()->status->value)->toBe(Order::STATUSES['executed']);

    $record = $order->fresh()->execution_record;
    expect($record['subscription_ids'])->toHaveCount(1)
        ->and($record['applied_coupon_ids'])->toHaveCount(1)
        ->and($record['wallet_ids'])->toHaveCount(1);

    // The subscription runs the override plan.
    $subscription = Subscription::query()->find($record['subscription_ids'][0]);
    expect($subscription->plan->parent_id)->toBe($plan->id)
        ->and($subscription->plan->amount_cents)->toBe(9900)
        ->and($subscription->plan->name)->toBe('Negotiated plan')
        // The negotiated charge name rides the override plan's charge.
        ->and($subscription->plan->charges()->first()->invoice_display_name)->toBe('Negotiated charge')
        ->and($subscription->active())->toBeTrue();

    expect(Wallet::query()->find($record['wallet_ids'][0]))->not->toBeNull();
});

it('fails the order when the quoted charge is no longer on the plan', function (): void {
    $organization = subscriptionOrderOrganization();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
    ]);

    $order = subscriptionOrder($organization, Quote::ORDER_TYPES['subscription_creation'], [
        'plans' => [[
            'id' => $plan->id,
            'localId' => (string) Str::uuid(),
            'payload' => [
                'charges' => [[
                    'id' => (string) Str::uuid(), // snapshot points at a removed charge
                    'chargeModel' => 'standard',
                    'billableMetric' => ['code' => 'metric'],
                ]],
            ],
            'overrides' => [
                'charges' => [['billableMetricCode' => 'metric', 'invoiceDisplayName' => 'Gone']],
            ],
        ]],
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($order->fresh()->status->value)->toBe(Order::STATUSES['failed'])
        ->and($order->fresh()->execution_record['errors'])->toBe(['charge_not_found']);
});

it('rejects an amendment whose target subscription is not active', function (): void {
    $organization = subscriptionOrderOrganization();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'amend_target',
        'subscription_at' => now(),
    ]);

    $quote = Quote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_type' => Quote::ORDER_TYPES['subscription_amendment'],
        'subscription_id' => $subscription->id,
    ]);

    $quoteVersion = QuoteVersion::factory()->create([
        'organization_id' => $organization->id,
        'quote_id' => $quote->id,
        'status' => 'approved',
        'approved_at' => now(),
        'currency' => 'EUR',
        'billing_items' => [
            'plans' => [[
                'id' => $plan->id,
                'payload' => [],
                'overrides' => ['amountCents' => 1200],
            ]],
        ],
    ]);

    $orderForm = OrderForm::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'quote_version_id' => $quoteVersion->id,
        'status' => 'signed',
        'signed_at' => now(),
    ]);

    $order = Order::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_form_id' => $orderForm->id,
        'status' => 'created',
        'execution_mode' => 'execute_in_lago',
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['subscription' => ['subscription_not_active']])
        ->and($order->fresh()->status->value)->toBe(Order::STATUSES['failed']);
});

it('executes an amendment: the quoted plan replaces the target via a plan change', function (): void {
    $organization = subscriptionOrderOrganization();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'amend_target',
        'started_at' => now()->subMonth(),
        'subscription_at' => now()->subMonth(),
    ]);

    $quote = Quote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_type' => Quote::ORDER_TYPES['subscription_amendment'],
        'subscription_id' => $subscription->id,
    ]);

    $quoteVersion = QuoteVersion::factory()->create([
        'organization_id' => $organization->id,
        'quote_id' => $quote->id,
        'status' => 'approved',
        'approved_at' => now(),
        'currency' => 'EUR',
        'billing_items' => [
            'plans' => [[
                'id' => $plan->id,
                'payload' => [],
                'overrides' => [
                    'amountCents' => 7700,
                    'name' => 'Renegotiated',
                ],
            ]],
        ],
    ]);

    $orderForm = OrderForm::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'quote_version_id' => $quoteVersion->id,
        'status' => 'signed',
        'signed_at' => now(),
    ]);

    $order = Order::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_form_id' => $orderForm->id,
        'status' => 'created',
        'execution_mode' => 'execute_in_lago',
    ]);

    $result = ExecuteService::call(order: $order);

    expect($result->failure())->toBeFalse()
        ->and($order->fresh()->status->value)->toBe(Order::STATUSES['executed']);

    // The upgrade rotates the subscription now: a new subscription chained
    // to the target, running a fresh override plan with the quoted amount.
    $record = $order->fresh()->execution_record;
    expect($record['subscription_ids'])->toHaveCount(1);

    $newSubscription = Subscription::query()->find($record['subscription_ids'][0]);
    expect($newSubscription->id)->not->toBe($subscription->id)
        ->and($newSubscription->previous_subscription_id)->toBe($subscription->id)
        ->and($newSubscription->plan->parent_id)->toBe($plan->id)
        ->and($newSubscription->plan->amount_cents)->toBe(7700)
        ->and($newSubscription->active())->toBeTrue();

    $subscription->refresh();
    expect($subscription->terminated())->toBeTrue();
});
