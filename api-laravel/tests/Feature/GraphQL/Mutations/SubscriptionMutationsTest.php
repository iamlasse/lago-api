<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/SubscriptionsResolverTest.php';

use App\Models\Plan;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Subscription;

/**
 * Ports of Rails' spec/graphql/mutations/subscriptions/{create,update,
 * terminate}_spec.rb (the scenarios this slice ports — premium overrides,
 * activation rules, connections and usage thresholds live with the features
 * that own them) over the frozen SDL.
 *
 * Ledger rows: gql:mutation:createSubscription, gql:mutation:updateSubscription,
 * gql:mutation:terminateSubscription.
 */
const CREATE_SUBSCRIPTION_MUTATION = <<<'GQL'
mutation($input: CreateSubscriptionInput!) {
    createSubscription(input: $input) {
        id
        status
        name
        externalId
        startedAt
        billingTime
        subscriptionAt
        endingAt
        progressiveBillingDisabled
        purchaseOrderNumber
        customer { id }
        plan { id amountCents }
    }
}
GQL;

const UPDATE_SUBSCRIPTION_MUTATION = <<<'GQL'
mutation($input: UpdateSubscriptionInput!) {
    updateSubscription(input: $input) {
        id
        name
        status
        subscriptionAt
        progressiveBillingDisabled
        purchaseOrderNumber
        billingEntityId
    }
}
GQL;

const TERMINATE_SUBSCRIPTION_MUTATION = <<<'GQL'
mutation($input: TerminateSubscriptionInput!) {
    terminateSubscription(input: $input) {
        id
        status
        terminatedAt
        canceledAt
        cancellationReason
        onTerminationCreditNote
        onTerminationInvoice
    }
}
GQL;

function gqlSubscriptionPlan(object $organization, array $attributes = []): Plan
{
    return Plan::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $attributes));
}

function gqlSubscriptionCustomer(object $organization, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $attributes));
}

it('creates a subscription', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $customer = gqlSubscriptionCustomer($organization, ['timezone' => null]);
    $plan = gqlSubscriptionPlan($organization, ['amount_cents' => 100]);
    $endingAt = CarbonImmutable::now()->startOfDay()->addYear();

    $response = gqlPost(
        CREATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'customerId' => $customer->id,
            'planId' => $plan->id,
            'name' => 'name',
            'externalId' => 'custom-external-id',
            'billingTime' => 'anniversary',
            'endingAt' => $endingAt->toIso8601String(),
            'progressiveBillingDisabled' => true,
            'purchaseOrderNumber' => 'PO-123',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.createSubscription');

    expect($payload['id'])->toBeString()
        ->and($payload['status'])->toBe('active')
        ->and($payload['name'])->toBe('name')
        ->and($payload['externalId'])->toBe('custom-external-id')
        ->and($payload['startedAt'])->toBeString()
        ->and($payload['billingTime'])->toBe('anniversary')
        ->and($payload['progressiveBillingDisabled'])->toBeTrue()
        ->and($payload['purchaseOrderNumber'])->toBe('PO-123')
        ->and($payload['customer']['id'])->toBe($customer->id)
        ->and($payload['plan']['id'])->toBe($plan->id)
        ->and($payload['plan']['amountCents'])->toBe('100');

    $subscription = Subscription::query()->find($payload['id']);

    expect($subscription)->not->toBeNull()
        ->and($subscription->external_id)->toBe('custom-external-id')
        ->and($subscription->organization_id)->toBe($organization->id)
        ->and($endingAt->format('Y-m-d'))->toBe(CarbonImmutable::instance($subscription->ending_at)->format('Y-m-d'));
})->group('ledger:gql:mutation:createSubscription');

it('falls back to a generated external id', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $customer = gqlSubscriptionCustomer($organization);
    $plan = gqlSubscriptionPlan($organization);

    $response = gqlPost(
        'mutation($input: CreateSubscriptionInput!) { createSubscription(input: $input) { id externalId } }',
        ['input' => [
            'customerId' => $customer->id,
            'planId' => $plan->id,
            'billingTime' => 'calendar',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.createSubscription.externalId'))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->group('ledger:gql:mutation:createSubscription');

it('binds the subscription to the requested billing entity', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $customer = gqlSubscriptionCustomer($organization);
    $plan = gqlSubscriptionPlan($organization);
    $billingEntity = App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        'mutation($input: CreateSubscriptionInput!) { createSubscription(input: $input) { id billingEntityId } }',
        ['input' => [
            'customerId' => $customer->id,
            'planId' => $plan->id,
            'billingTime' => 'calendar',
            'billingEntityId' => $billingEntity->id,
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.createSubscription.billingEntityId'))->toBe($billingEntity->id);
})->group('ledger:gql:mutation:createSubscription');

it('returns not_found for an unknown customer and plan', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = gqlSubscriptionPlan($organization);

    $unknownCustomer = gqlPost(
        CREATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'customerId' => '00000000-0000-0000-0000-000000000000',
            'planId' => $plan->id,
            'billingTime' => 'calendar',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($unknownCustomer->json('data.createSubscription'))->toBeNull()
        ->and($unknownCustomer->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['customer' => ['not_found']],
        ]);

    $customer = gqlSubscriptionCustomer($organization);

    $unknownPlan = gqlPost(
        CREATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'customerId' => $customer->id,
            'planId' => '00000000-0000-0000-0000-000000000000',
            'billingTime' => 'calendar',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($unknownPlan->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['plan' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:createSubscription');

it('returns unauthorized on createSubscription without a token', function () {
    // GraphQL input validation runs before the resolvers — send a
    // well-formed input so the unauthorized error is the one surfacing.
    $response = gqlPost(CREATE_SUBSCRIPTION_MUTATION, ['input' => [
        'customerId' => '00000000-0000-0000-0000-000000000000',
        'planId' => '00000000-0000-0000-0000-000000000000',
        'billingTime' => 'calendar',
    ]]);

    expect($response->json('errors.0.message'))->toBe('unauthorized');
})->group('ledger:gql:mutation:createSubscription');

it('updates a subscription', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => gqlSubscriptionPlan($organization)->id,
        'external_id' => 'ext-update',
        'name' => 'Old name',
    ]);

    $response = gqlPost(
        UPDATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'id' => $subscription->id,
            'name' => 'New name',
            'progressiveBillingDisabled' => true,
            'purchaseOrderNumber' => 'PO-456',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.updateSubscription');

    expect($payload['id'])->toBe($subscription->id)
        ->and($payload['name'])->toBe('New name')
        ->and($payload['progressiveBillingDisabled'])->toBeTrue()
        ->and($payload['purchaseOrderNumber'])->toBe('PO-456')
        ->and($subscription->fresh()->name)->toBe('New name');
})->group('ledger:gql:mutation:updateSubscription');

it('moves a pending subscription_at on update', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    // Rails only processes a subscription_at change on a subscription
    // starting in the future (pending, no previous subscription).
    $pending = Subscription::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => gqlSubscriptionPlan($organization)->id,
        'external_id' => 'ext-move',
        'subscription_at' => now()->addDays(3),
    ]);

    $newDate = now()->addDays(10)->startOfDay();

    $response = gqlPost(
        UPDATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'id' => $pending->id,
            'subscriptionAt' => $newDate->toIso8601String(),
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.updateSubscription');

    expect($payload['status'])->toBe('pending')
        ->and(CarbonImmutable::instance($pending->fresh()->subscription_at)->startOfDay()->equalTo($newDate))
        ->toBeTrue();
})->group('ledger:gql:mutation:updateSubscription');

it('returns not_found when updating an unknown subscription', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $response = gqlPost(
        UPDATE_SUBSCRIPTION_MUTATION,
        ['input' => [
            'id' => '00000000-0000-0000-0000-000000000000',
            'name' => 'Nope',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateSubscription'))->toBeNull()
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['subscription' => ['not_found']],
        ]);
})->group('ledger:gql:mutation:updateSubscription');

it('terminates a subscription of a pay-in-advance plan', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = gqlSubscriptionPlan($organization, ['pay_in_advance' => true]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => $plan->id,
        'external_id' => 'ext-terminate',
        'started_at' => now()->subDay(),
        'activated_at' => now()->subDay(),
    ]);

    $response = gqlPost(
        TERMINATE_SUBSCRIPTION_MUTATION,
        ['input' => ['id' => $subscription->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.terminateSubscription');

    expect($payload['id'])->toBe($subscription->id)
        ->and($payload['status'])->toBe('terminated')
        ->and($payload['terminatedAt'])->toBeString()
        ->and($payload['onTerminationCreditNote'])->toBe('credit')
        ->and($payload['onTerminationInvoice'])->toBe('generate');
})->group('ledger:gql:mutation:terminateSubscription');

it('honors the on termination behaviors on terminate', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = gqlSubscriptionPlan($organization, ['pay_in_advance' => true]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => $plan->id,
        'external_id' => 'ext-terminate-2',
        'started_at' => now()->subDay(),
        'activated_at' => now()->subDay(),
    ]);

    $response = gqlPost(
        TERMINATE_SUBSCRIPTION_MUTATION,
        ['input' => ['id' => $subscription->id, 'onTerminationCreditNote' => 'skip', 'onTerminationInvoice' => 'skip']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.terminateSubscription');

    expect($payload['status'])->toBe('terminated')
        ->and($payload['onTerminationCreditNote'])->toBe('skip')
        ->and($payload['onTerminationInvoice'])->toBe('skip')
        ->and($subscription->fresh()->on_termination_credit_note)->toBe('skip')
        ->and($subscription->fresh()->on_termination_invoice)->toBe('skip');
})->group('ledger:gql:mutation:terminateSubscription');

it('cancels a pending subscription on terminate', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $previous = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => gqlSubscriptionPlan($organization)->id,
        'external_id' => 'ext-pending-term',
    ]);

    $pending = Subscription::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $previous->customer_id,
        'plan_id' => $previous->plan_id,
        'external_id' => 'ext-pending-term',
        'previous_subscription_id' => $previous->id,
        'subscription_at' => now()->addDays(3),
    ]);

    $response = gqlPost(
        TERMINATE_SUBSCRIPTION_MUTATION,
        ['input' => ['id' => $pending->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.terminateSubscription');

    // Rails' pending branch only mark_as_canceled! — a cancellation reason is
    // set for incomplete subscriptions only.
    expect($payload['id'])->toBe($pending->id)
        ->and($payload['status'])->toBe('canceled')
        ->and($payload['canceledAt'])->toBeString()
        ->and($payload['cancellationReason'])->toBeNull()
        ->and($pending->fresh()->canceled_at)->not->toBeNull();
})->group('ledger:gql:mutation:terminateSubscription');

it('cancels an incomplete subscription with a manual cancellation reason', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $incomplete = Subscription::factory()->incomplete()->create([
        'organization_id' => $organization->id,
        'customer_id' => gqlSubscriptionCustomer($organization)->id,
        'plan_id' => gqlSubscriptionPlan($organization)->id,
        'external_id' => 'ext-incomplete-term',
    ]);

    // A real incomplete subscription is a payment-gated one: it carries a
    // pending activation rule and an open gating invoice — both are needed
    // for the cancellation to run (Rails: ActivationRules::CancelService).
    \App\Models\Subscription\ActivationRule\Payment::factory()->create([
        'organization_id' => $organization->id,
        'subscription_id' => $incomplete->id,
        'status' => 'pending',
    ]);

    $invoice = \App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $incomplete->customer_id,
        'invoice_type' => \App\Enums\InvoiceType::Subscription,
        'status' => \App\Enums\InvoiceStatus::Open,
    ]);
    \App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $incomplete->id,
    ]);

    $response = gqlPost(
        TERMINATE_SUBSCRIPTION_MUTATION,
        ['input' => ['id' => $incomplete->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.terminateSubscription');

    expect($payload['id'])->toBe($incomplete->id)
        ->and($payload['status'])->toBe('canceled')
        ->and($payload['canceledAt'])->toBeString()
        ->and($payload['cancellationReason'])->toBe('manual');
})->group('ledger:gql:mutation:terminateSubscription');

it('returns not_found when terminating an unknown subscription', function () {
    [$organization, $user] = gqlSubscriptionsSetup();

    $response = gqlPost(
        TERMINATE_SUBSCRIPTION_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.terminateSubscription'))->toBeNull()
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['subscription' => ['not_found']],
        ]);
})->group('ledger:gql:mutation:terminateSubscription');
