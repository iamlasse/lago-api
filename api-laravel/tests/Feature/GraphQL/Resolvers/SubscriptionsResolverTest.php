<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Plan;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Models\BillingEntity;

/**
 * Ports of Rails' spec/graphql/resolvers/{subscription_resolver,
 * subscriptions_resolver}_spec.rb (the scenarios this slice ports) over the
 * frozen SDL.
 *
 * Ledger rows: gql:query:subscription, gql:query:subscriptions.
 */
afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function gqlSubscriptionsSetup(): array
{
    $organization = gqlCreateOrganization();
    // Rails creates the default billing entity with the organization
    // (Organizations::CreateService); the frozen-schema port creates it
    // explicitly in the fixture.
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlMakeSubscription(object $organization, array $attributes = []): Subscription
{
    $customer = $attributes['customer'] ?? Customer::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $plan = $attributes['plan'] ?? Plan::factory()->create([
        'organization_id' => $organization->id,
    ]);

    unset($attributes['customer'], $attributes['plan']);

    return Subscription::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub-external-1',
    ], $attributes));
}

const SUBSCRIPTIONS_LIST_QUERY = <<<'GQL'
query {
    subscriptions(limit: 5, planCode: "%s", status: [active]) {
        collection { id externalId plan { code } }
        metadata { currentPage totalCount }
    }
}
GQL;

it('returns a filtered list of subscriptions with metadata', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $first = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'external_id' => 'ext-a']);
    $second = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'external_id' => 'ext-b']);
    gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'status' => 'terminated', 'external_id' => 'ext-c']);
    // A subscription of another plan does not match the plan_code filter.
    gqlMakeSubscription($organization, ['customer' => $customer, 'external_id' => 'ext-d']);

    $response = gqlPost(
        sprintf(SUBSCRIPTIONS_LIST_QUERY, $plan->code),
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.subscriptions');

    expect($payload['collection'])->toHaveCount(2)
        ->and(collect($payload['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and($payload['collection'][0]['plan']['code'])->toBe($plan->code)
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(2);
})->group('ledger:gql:query:subscriptions');

it('excludes a subscription whose next subscription is listed (exclude_next_subscriptions)', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $previous = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'external_id' => 'ext-1']);
    $next = Subscription::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'ext-1',
        'previous_subscription_id' => $previous->id,
        'subscription_at' => now()->addMonth(),
    ]);

    // Without a status filter the pending next subscription is not listed
    // either (it carries a previous subscription and none of the escape
    // hatches apply) — only the previous one shows.
    $response = gqlPost(
        'query { subscriptions(limit: 5) { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.subscriptions.collection.*.id'))->toBe([$previous->id])
        ->and($response->json('data.subscriptions.metadata.totalCount'))->toBe(1);

    // With a status filter the previous subscription drops out of the filter,
    // so the escape hatch keeps the pending next subscription listed.
    $response = gqlPost(
        'query { subscriptions(limit: 5, status: [pending]) { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.subscriptions.collection.*.id'))->toBe([$next->id])
        ->and($response->json('data.subscriptions.metadata.totalCount'))->toBe(1);
})->group('ledger:gql:query:subscriptions');

it('filters by billing entity ids', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $eu = BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $us = BillingEntity::factory()->create(['organization_id' => $organization->id]);

    $euSubscription = gqlMakeSubscription($organization, ['billing_entity_id' => $eu->id]);
    $usSubscription = gqlMakeSubscription($organization, ['billing_entity_id' => $us->id]);

    $query = static fn (string $ids): Illuminate\Testing\TestResponse => gqlPost(
        "query { subscriptions(limit: 5, billingEntityIds: [{$ids}]) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($query('"'.$eu->id.'"')->json('data.subscriptions.collection.*.id'))->toBe([$euSubscription->id])
        ->and($query('"'.$eu->id.'"')->json('data.subscriptions.metadata.totalCount'))->toBe(1)
        ->and($query('"'.$eu->id.'", "'.$us->id.'"')->json('data.subscriptions.collection.*.id'))
        ->toEqualCanonicalizing([$euSubscription->id, $usSubscription->id]);
})->group('ledger:gql:query:subscriptions');

it('filters by external id, external customer id and currency', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $brlPlan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_currency' => 'BRL']);
    $customer = Customer::factory()->create(['organization_id' => $organization->id, 'external_id' => 'cust-ext-1']);

    $target = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'external_id' => 'ext-target']);
    $brl = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $brlPlan, 'external_id' => 'ext-brl']);

    $list = static fn (string $args): array => gqlPost(
        "query { subscriptions(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.subscriptions');

    expect(collect($list('externalId: "ext-target"')['collection'])->pluck('id')->all())->toBe([$target->id])
        ->and(collect($list('currency: "BRL"')['collection'])->pluck('id')->all())->toBe([$brl->id])
        ->and(collect($list('externalCustomerId: "cust-ext-1"')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$target->id, $brl->id]);
})->group('ledger:gql:query:subscriptions');

it('searches across subscription, plan and customer fields', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'Rocket Plan']);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $named = gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'name' => 'Zephyr Deal']);
    gqlMakeSubscription($organization, ['customer' => $customer, 'plan' => $plan, 'external_id' => 'ext-other']);

    $response = gqlPost(
        'query { subscriptions(limit: 5, searchTerm: "zephyr") { collection { id } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.subscriptions.collection.*.id'))->toBe([$named->id]);
})->group('ledger:gql:query:subscriptions');

it('paginates with kaminari defaults', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    for ($i = 0; $i < 4; $i++) {
        gqlMakeSubscription($organization, ['plan' => $plan, 'external_id' => "ext-{$i}"]);
    }

    $response = gqlPost(
        'query { subscriptions(limit: 3, page: 2) { collection { id } metadata { currentPage limitValue totalPages totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.subscriptions.collection'))->toHaveCount(1)
        ->and($response->json('data.subscriptions.metadata'))->toBe([
            'currentPage' => 2,
            'limitValue' => 3,
            'totalPages' => 2,
            'totalCount' => 4,
        ]);
})->group('ledger:gql:query:subscriptions');

it('returns unauthorized on subscriptions without a token', function (): void {
    $response = gqlPost('query { subscriptions { collection { id } } }');

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:subscriptions');

const SUBSCRIPTION_QUERY = <<<'GQL'
query($subscriptionId: ID, $externalId: ID) {
    subscription(id: $subscriptionId, externalId: $externalId) {
        id
        externalId
        name
        status
        startedAt
        endingAt
        progressiveBillingDisabled
        billingTime
        usageThresholds { amountCents }
        plan { id code }
        nextSubscriptionType
        nextSubscriptionAt
        downgradePlanDate
        previousPlan { id name }
        previousSubscription { id downgradePlanDate }
    }
}
GQL;

it('returns a single subscription by id and by external id', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $subscription = gqlMakeSubscription($organization, [
        'name' => 'Primary',
        'billing_time' => 'anniversary',
        'started_at' => '2024-04-01 00:00:00',
    ]);

    $byId = gqlPost(
        SUBSCRIPTION_QUERY,
        ['subscriptionId' => $subscription->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $byId->json('data.subscription');

    expect($payload['id'])->toBe($subscription->id)
        ->and($payload['externalId'])->toBe('sub-external-1')
        ->and($payload['name'])->toBe('Primary')
        ->and($payload['status'])->toBe('active')
        ->and($payload['billingTime'])->toBe('anniversary')
        ->and($payload['progressiveBillingDisabled'])->toBeFalse()
        ->and($payload['startedAt'])->toMatch('/^\d{4}-04-01T\d{2}:\d{2}:\d{2}Z$/')
        ->and($payload['plan']['id'])->toBe($subscription->plan_id)
        ->and($payload['usageThresholds'])->toBe([])
        // No previous or next subscription.
        ->and($payload['previousPlan'])->toBeNull()
        ->and($payload['previousSubscription'])->toBeNull()
        ->and($payload['downgradePlanDate'])->toBeNull()
        ->and($payload['nextSubscriptionType'])->toBeNull();

    $byExternalId = gqlPost(
        SUBSCRIPTION_QUERY,
        ['externalId' => 'sub-external-1'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($byExternalId->json('data.subscription.id'))->toBe($subscription->id);
})->group('ledger:gql:query:subscription');

it('errors when neither id nor external id is provided', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $response = gqlPost(
        SUBSCRIPTION_QUERY,
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.message'))->toBe('You must provide either `id` or `external_id`.');
})->group('ledger:gql:query:subscription');

it('returns the not_found envelope for an unknown subscription', function (): void {
    [$organization, $user] = gqlSubscriptionsSetup();

    $response = gqlPost(
        SUBSCRIPTION_QUERY,
        ['subscriptionId' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.subscription'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['subscription' => ['not_found']],
        ]);
})->group('ledger:gql:query:subscription');

it('computes the downgrade plan date and next subscription type on a pending downgrade', function (): void {
    // Fixtures (JWT included) are created before the frozen clock: the auth
    // token's exp must stay valid against the real time.
    [$organization, $user] = gqlSubscriptionsSetup();
    $headers = gqlAuthHeaders($user, $organization->id);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 12:00:00', 'UTC'));

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 500_00]);
    $lowerPlan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 100_00]);

    $subscription = gqlMakeSubscription($organization, [
        'customer' => $customer,
        'plan' => $plan,
        'billing_time' => 'anniversary',
        'external_id' => 'ext-downgrade',
        'subscription_at' => '2026-04-22 00:00:00',
        'started_at' => '2026-04-22 00:00:00',
    ]);

    $pending = Subscription::factory()->pending()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $lowerPlan->id,
        'external_id' => 'ext-downgrade',
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => now(),
    ]);

    $response = gqlPost(
        SUBSCRIPTION_QUERY,
        ['subscriptionId' => $subscription->id],
        $headers,
    );

    $payload = $response->json('data.subscription');

    expect($payload['nextSubscriptionType'])->toBe('downgrade')
        ->and($payload['downgradePlanDate'])->toMatch('/^2026-05-22/')
        ->and($payload['previousPlan'])->toBeNull();

    // The pending subscription exposes the previous plan and subscription.
    $pendingResponse = gqlPost(
        SUBSCRIPTION_QUERY,
        ['subscriptionId' => $pending->id],
        $headers,
    );

    $pendingPayload = $pendingResponse->json('data.subscription');

    expect($pendingPayload['previousPlan']['id'])->toBe($plan->id)
        ->and($pendingPayload['previousPlan']['name'])->toBe($plan->name)
        ->and($pendingPayload['previousSubscription']['id'])->toBe($subscription->id)
        ->and($pendingPayload['previousSubscription']['downgradePlanDate'])->toMatch('/^2026-05-22/')
        ->and($pendingPayload['downgradePlanDate'])->toBeNull();
})->group('ledger:gql:query:subscription');
