<?php

declare(strict_types=1);

uses()->group('ledger:gql:mutation:createSubscriptionAlert',
    'ledger:gql:mutation:updateSubscriptionAlert',
    'ledger:gql:mutation:destroySubscriptionAlert',
    'ledger:gql:mutation:createCustomerWalletAlert',
    'ledger:gql:mutation:updateCustomerWalletAlert',
    'ledger:gql:mutation:destroyCustomerWalletAlert',
    'ledger:gql:query:alert',
    'ledger:gql:query:alerts');

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Subscription;

/**
 * Ports of Rails' spec/graphql/mutations/{subscriptions,wallets}/alerts/*
 * _spec.rb (core scenarios) and the Subscription.alert / lifetimeUsage /
 * usageThresholds field resolvers over the frozen SDL.
 */
function gqlAlertsSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('alerts-'.uniqid().'@example.com');

    gqlCreateMembership($user, $organization);

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'gql-sub-1',
        'status' => 1,
    ]);

    return [$organization->refresh(), $user, $subscription];
}

const CREATE_SUBSCRIPTION_ALERT_MUTATION = <<<'GQL'
mutation($input: CreateSubscriptionAlertInput!) {
    createSubscriptionAlert(input: $input) {
        id
        alertType
        code
        name
        direction
        thresholds { code value recurring }
    }
}
GQL;

const UPDATE_SUBSCRIPTION_ALERT_MUTATION = <<<'GQL'
mutation($input: UpdateSubscriptionAlertInput!) {
    updateSubscriptionAlert(input: $input) {
        id
        code
        name
    }
}
GQL;

const DESTROY_SUBSCRIPTION_ALERT_MUTATION = <<<'GQL'
mutation($input: DestroySubscriptionAlertInput!) {
    destroySubscriptionAlert(input: $input) {
        id
        code
    }
}
GQL;

const SUBSCRIPTION_ALERT_QUERY = <<<'GQL'
query($id: ID!) {
    node(id: $id) { id }
    me { id }
}
GQL;

it('creates a subscription alert over GraphQL', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();

    $response = gqlPost(CREATE_SUBSCRIPTION_ALERT_MUTATION, [
        'input' => [
            'subscriptionId' => $subscription->id,
            'alertType' => 'current_usage_amount',
            'code' => 'gql_warn',
            'name' => 'GQL Warn',
            'thresholds' => [['code' => 'w', 'value' => '100', 'recurring' => false]],
        ],
    ], gqlAuthHeaders($user, $organization->id));

    $data = $response->json('data.createSubscriptionAlert');

    if ($response->json('errors') !== null) {
        dump('GQLERR: '.json_encode($response->json('errors')));
    }

    expect($response->json('errors'))->toBeNull()
        ->and($data['code'])->toBe('gql_warn')
        ->and($data['alertType'])->toBe('current_usage_amount')
        ->and($data['direction'])->toBe('increasing')
        ->and($data['thresholds'][0]['code'])->toBe('w');
});

it('updates and destroys a subscription alert over GraphQL', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();

    $created = gqlPost(CREATE_SUBSCRIPTION_ALERT_MUTATION, [
        'input' => [
            'subscriptionId' => $subscription->id,
            'alertType' => 'current_usage_amount',
            'code' => 'gql_u',
            'thresholds' => [['value' => '10']],
        ],
    ], gqlAuthHeaders($user, $organization->id))->json('data.createSubscriptionAlert');

    $updated = gqlPost(UPDATE_SUBSCRIPTION_ALERT_MUTATION, [
        'input' => ['id' => $created['id'], 'name' => 'Renamed GQL'],
    ], gqlAuthHeaders($user, $organization->id))->json('data.updateSubscriptionAlert');

    expect($updated['name'])->toBe('Renamed GQL');

    $destroyed = gqlPost(DESTROY_SUBSCRIPTION_ALERT_MUTATION, [
        'input' => ['id' => $created['id']],
    ], gqlAuthHeaders($user, $organization->id))->json('data.destroySubscriptionAlert');

    expect($destroyed['code'])->toBe('gql_u');
});

it('creates a wallet alert over GraphQL', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();
    $wallet = App\Models\Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $subscription->customer_id,
        'balance_cents' => 5000,
    ]);

    $response = gqlPost(
        <<<'GQL'
mutation($input: CreateCustomerWalletAlertInput!) {
    createCustomerWalletAlert(input: $input) {
        id
        alertType
        code
        direction
        walletId
    }
}
GQL,
        [
            'input' => [
                'walletId' => $wallet->id,
                'alertType' => 'wallet_balance_amount',
                'code' => 'gql_wallet_warn',
                'thresholds' => [['value' => '1000']],
            ],
        ],
        gqlAuthHeaders($user, $organization->id),
    );

    $data = $response->json('data.createCustomerWalletAlert');

    if ($response->json('errors') !== null) {
        dump('GQLERR: '.json_encode($response->json('errors')));
    }

    expect($response->json('errors'))->toBeNull()
        ->and($data['alertType'])->toBe('wallet_balance_amount')
        ->and($data['direction'])->toBe('decreasing');
});

it('exposes the subscription alert query field', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();

    $alert = App\Models\UsageMonitoring\Alert::query()->create([
        'organization_id' => $organization->id,
        'subscription_external_id' => 'gql-sub-1',
        'alert_type' => 'current_usage_amount',
        'code' => 'gql_q',
        'direction' => 'increasing',
    ]);

    $response = gqlPost(
        <<<'GQL'
query($alertId: ID!) {
    alert(id: $alertId) { id code alertType }
}
GQL,
        ['alertId' => $alert->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $alertData = $response->json('data.alert');

    if ($response->json('errors') !== null) {
        dump('GQLERR: '.json_encode($response->json('errors')));
    }

    expect($response->json('errors'))->toBeNull()
        ->and($alertData['code'])->toBe('gql_q');
});

it('exposes lifetimeUsage and usageThresholds on the subscription type', function (): void {
    config(['lago.license' => 'premium-license-token']);
    [$organization, $user, $subscription] = gqlAlertsSetup();
    $organization->premium_integrations = ['progressive_billing'];
    $organization->save();

    App\Models\UsageThreshold::query()->create([
        'organization_id' => $organization->id,
        'plan_id' => $subscription->plan_id,
        'amount_cents' => 10000,
        'recurring' => false,
    ]);

    $subscription->createLifetimeUsage(['current_usage_amount_cents' => 2500]);

    $response = gqlPost(
        <<<'GQL'
query($id: ID!) {
    subscription(id: $id) {
        usageThresholds { thresholdDisplayName amountCents recurring }
        lifetimeUsage {
            totalUsageAmountCents
            lastThresholdAmountCents
            nextThresholdAmountCents
        }
    }
}
GQL,
        ['id' => $subscription->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $data = $response->json('data.subscription');

    if ($response->json('errors') !== null) {
        dump('GQLERR: '.json_encode($response->json('errors')));
    }

    expect($response->json('errors'))->toBeNull()
        ->and($data['usageThresholds'])->toHaveCount(1)
        ->and($data['usageThresholds'][0]['amountCents'])->toBe('10000')
        ->and($data['lifetimeUsage']['totalUsageAmountCents'])->toBe('2500');
});
it('updates and destroys a wallet alert over GraphQL', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();
    $wallet = App\Models\Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $subscription->customer_id,
        'balance_cents' => 5000,
    ]);

    $created = gqlPost(
        <<<'GQL'
mutation($input: CreateCustomerWalletAlertInput!) {
    createCustomerWalletAlert(input: $input) {
        id
        code
    }
}
GQL,
        [
            'input' => [
                'walletId' => $wallet->id,
                'alertType' => 'wallet_balance_amount',
                'code' => 'gql_wallet_u',
                'thresholds' => [['value' => '1000']],
            ],
        ],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.createCustomerWalletAlert');

    $updated = gqlPost(
        <<<'GQL'
mutation($input: UpdateCustomerWalletAlertInput!) {
    updateCustomerWalletAlert(input: $input) {
        id
        code
        name
    }
}
GQL,
        ['input' => ['id' => $created['id'], 'name' => 'Renamed Wallet GQL']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($updated->json('errors'))->toBeNull()
        ->and($updated->json('data.updateCustomerWalletAlert.name'))->toBe('Renamed Wallet GQL');

    $destroyed = gqlPost(
        <<<'GQL'
mutation($input: DestroyCustomerWalletAlertInput!) {
    destroyCustomerWalletAlert(input: $input) {
        id
        code
    }
}
GQL,
        ['input' => ['id' => $created['id']]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($destroyed->json('errors'))->toBeNull()
        ->and($destroyed->json('data.destroyCustomerWalletAlert.code'))->toBe('gql_wallet_u');
});

it('exposes the alerts list query over the frozen schema', function (): void {
    [$organization, $user, $subscription] = gqlAlertsSetup();

    App\Models\UsageMonitoring\Alert::query()->create([
        'organization_id' => $organization->id,
        'subscription_external_id' => 'gql-sub-1',
        'alert_type' => 'current_usage_amount',
        'code' => 'gql_list_1',
        'direction' => 'increasing',
    ]);
    App\Models\UsageMonitoring\Alert::query()->create([
        'organization_id' => $organization->id,
        'subscription_external_id' => 'gql-sub-1',
        'alert_type' => 'lifetime_usage_amount',
        'code' => 'gql_list_2',
        'direction' => 'increasing',
    ]);

    $response = gqlPost(
        <<<'GQL'
query($externalId: String!) {
    alerts(subscriptionExternalId: $externalId) {
        collection { id code alertType }
        metadata { currentPage totalCount }
    }
}
GQL,
        ['externalId' => 'gql-sub-1'],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json();

    if (($payload['errors'] ?? null) !== null) {
        dump('GQLERR: '.json_encode($payload['errors']));
    }

    $codes = collect($payload['data']['alerts']['collection'] ?? [])->pluck('code')->all();

    expect($response->json('errors'))->toBeNull()
        ->and($codes)->toContain('gql_list_1')
        ->and($codes)->toContain('gql_list_2')
        ->and($payload['data']['alerts']['metadata']['totalCount'])->toBe(2);
});
