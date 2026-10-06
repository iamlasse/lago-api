<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Http;
use App\Models\UsageMonitoring\Alert;

/**
 * Ports of the Rails analytics/data-api/usage/events resolvers' specs
 * (spec/graphql/resolvers/analytics/, spec/graphql/resolvers/data_api/) and
 * the alert/wallet-transaction fetches.
 *
 * Ledger rows: gql:query:{grossRevenues,invoiceCollections,invoicedUsages,
 * mrrs,overdueBalances,dataApiMrrs,dataApiMrrsPlans,dataApiPrepaidCredits,
 * dataApiRevenueStreams,dataApiRevenueStreamsCustomers,
 * dataApiRevenueStreamsPlans,dataApiUsages,dataApiUsagesAggregatedAmounts,
 * dataApiUsagesInvoiced,customerUsage,customerProjectedUsage,event,events,
 * eventTypes,selectableBillableMetrics,selectablePlans,subscriptionAlert,
 * subscriptionAlerts,walletAlert,walletAlerts,walletTransactionConsumptions,
 * walletTransactionFundings}.
 */
function gqlAnalyticsSetup(): array
{
    $organization = gqlCreateOrganization();
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser('analytics@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

it('answers the analytics collections', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    // mrrs / invoicedUsages / invoiceCollections are premium-gated in Rails;
    // the overdueBalances / grossRevenues ones are not.
    $response = gqlPost(<<<'GQL'
    query {
        grossRevenues(months: 3) { collection { month amountCents currency invoicesCount } metadata { totalCount } }
        overdueBalances(months: 3) { collection { month amountCents currency } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.grossRevenues.metadata.totalCount'))->toBe(0)
        ->and($response->json('data.overdueBalances.metadata.totalCount'))->toBe(0);

    // The billing_entity_code filter resolves to the entity id; an unknown
    // code answers the not_found envelope.
    $code = $organization->billingEntities()->first()?->code ?? 'default';

    $response = gqlPost(<<<'GQL'
    query($code: String) {
        grossRevenues(billingEntityCode: $code) { metadata { totalCount } }
    }
    GQL, ['code' => $code], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.grossRevenues.metadata.totalCount'))->toBe(0);

    $response = gqlPost(<<<'GQL'
    query {
        grossRevenues(billingEntityCode: "unknown-entity") { metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');

    // The premium gate answers the unauthorized envelope without a license.
    $response = gqlPost(<<<'GQL'
    query {
        mrrs { collection { month } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');

    config()->set('lago.license', 'premium-token');

    $response = gqlPost(<<<'GQL'
    query {
        mrrs { collection { month } metadata { totalCount } }
        invoicedUsages { metadata { totalCount } }
        invoiceCollections { metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.mrrs.metadata'))->not->toBeNull()
        ->and($response->json('data.invoicedUsages.metadata'))->not->toBeNull()
        ->and($response->json('data.invoiceCollections.metadata'))->not->toBeNull();
})->group('ledger:gql:query:grossRevenues', 'ledger:gql:query:overdueBalances', 'ledger:gql:query:mrrs', 'ledger:gql:query:invoicedUsages', 'ledger:gql:query:invoiceCollections');

it('proxies the data api queries', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    config()->set('lago.license', 'premium-token');
    config()->set('lago.data_api_url', 'https://data.lago.test');
    config()->set('lago.data_api_bearer_token', 'data-api-token');

    $month = [
        'start_of_period_dt' => '2026-01-01',
        'end_of_period_dt' => '2026-01-31',
        'amount_currency' => 'EUR',
    ];

    Http::fake([
        // Full wire payloads: the DataApi* object types carry non-null fields.
        // Laravel matches the FIRST registered pattern, so the sub-path fakes
        // come before their path-prefix prefixes.
        'data.lago.test/mrrs/*/plans*' => Http::response(['mrrs_plans' => [array_merge($month, [
            'plan_id' => 'plan-1',
            'plan_code' => 'pro',
            'plan_name' => 'Pro',
            'plan_interval' => 'monthly',
            'mrr' => 1000.0,
            'mrr_share' => 1.0,
            'active_customers_count' => '1',
            'active_customers_share' => 1.0,
        ])], 'meta' => [
            'current_page' => 1,
            'next_page' => 0,
            'prev_page' => 0,
            'total_count' => 1,
            'total_pages' => 1,
        ]], 200),
        'data.lago.test/usages/*/invoiced*' => Http::response([], 200),
        'data.lago.test/usages/*/aggregated_amounts*' => Http::response([], 200),
        'data.lago.test/revenue_streams/*/customers*' => Http::response(['revenue_streams_customers' => [], 'meta' => null], 200),
        'data.lago.test/revenue_streams/*/plans*' => Http::response(['revenue_streams_plans' => [], 'meta' => null], 200),
        'data.lago.test/mrrs/*' => Http::response([array_merge($month, [
            'starting_mrr' => '900',
            'ending_mrr' => '1000',
            'mrr_new' => '100',
            'mrr_expansion' => '0',
            'mrr_contraction' => '0',
            'mrr_churn' => '0',
        ])], 200),
        'data.lago.test/prepaid_credits/*' => Http::response([], 200),
        'data.lago.test/revenue_streams/*' => Http::response([], 200),
        'data.lago.test/usages/*' => Http::response([array_merge($month, [
            'billable_metric_code' => 'api_calls',
            'amount_cents' => '0',
            'units' => 1.0,
            'is_billable_metric_deleted' => false,
        ])], 200),
    ]);

    $response = gqlPost(<<<'GQL'
    query {
        dataApiMrrs(timeGranularity: monthly) { collection { endingMrr amountCurrency } metadata { totalCount } }
        dataApiPrepaidCredits { metadata { totalCount } }
        dataApiRevenueStreams { metadata { totalCount } }
        dataApiUsages { collection { billableMetricCode units } }
        dataApiMrrsPlans { collection { planCode } metadata { currentPage totalCount } }
        dataApiRevenueStreamsCustomers { collection { externalCustomerId } }
        dataApiRevenueStreamsPlans { collection { planCode } }
        dataApiUsagesAggregatedAmounts { metadata { totalCount } }
        dataApiUsagesInvoiced { metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeNull();

    $payload = $response->json('data');

    // The Data API payload rides the wire verbatim under the collection key
    // (the wire shapes come from the frozen SDL's DataApi* types).
    expect($payload['dataApiMrrs']['metadata']['totalCount'])->toBe(1)
        ->and($payload['dataApiMrrs']['collection'][0]['endingMrr'])->toBe('1000')
        ->and($payload['dataApiUsages']['collection'][0]['billableMetricCode'])->toBe('api_calls')
        ->and($payload['dataApiMrrsPlans']['collection'][0]['planCode'])->toBe('pro')
        ->and($payload['dataApiPrepaidCredits']['metadata']['totalCount'])->toBe(0);
})->group('ledger:gql:query:dataApiMrrs', 'ledger:gql:query:dataApiMrrsPlans', 'ledger:gql:query:dataApiPrepaidCredits', 'ledger:gql:query:dataApiRevenueStreams', 'ledger:gql:query:dataApiRevenueStreamsCustomers', 'ledger:gql:query:dataApiRevenueStreamsPlans', 'ledger:gql:query:dataApiUsages', 'ledger:gql:query:dataApiUsagesAggregatedAmounts', 'ledger:gql:query:dataApiUsagesInvoiced');

it('answers the customer usage queries', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);

    // Rails: without a matching subscription the mutation answers
    // not_allowed(no_active_subscription).
    $response = gqlPost(<<<'GQL'
    query($customerId: ID, $subscriptionId: ID!) {
        customerUsage(customerId: $customerId, subscriptionId: $subscriptionId) { amountCents }
    }
    GQL, ['customerId' => $customer->id, 'subscriptionId' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('no_active_subscription');

    // With an active subscription on an empty plan the usage is zero.
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 0]);
    $subscription = App\Models\Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'status' => 0,
    ]);

    $response = gqlPost(<<<'GQL'
    query($customerId: ID, $subscriptionId: ID!) {
        customerUsage(customerId: $customerId, subscriptionId: $subscriptionId) { amountCents currency }
    }
    GQL, ['customerId' => $customer->id, 'subscriptionId' => $subscription->id],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.customerUsage.amountCents'))->toBe('0');

    $response = gqlPost(<<<'GQL'
    query($customerId: ID, $subscriptionId: ID!) {
        customerProjectedUsage(customerId: $customerId, subscriptionId: $subscriptionId) { amountCents currency }
    }
    GQL, ['customerId' => $customer->id, 'subscriptionId' => $subscription->id],
        gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.customerProjectedUsage.amountCents'))->toBe('0');
})->group('ledger:gql:query:customerUsage', 'ledger:gql:query:customerProjectedUsage');

it('answers the event queries and the event type catalog', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    App\Models\Event::factory()->create([
        'organization_id' => $organization->id,
        'transaction_id' => 'tx-1',
        'code' => 'api_call',
        'external_subscription_id' => 'sub-ext',
    ]);

    // The single-event lookup is keyed by the mandatory transaction id.
    $response = gqlPost(<<<'GQL'
    query {
        event(transactionId: "tx-1") { code transactionId externalSubscriptionId }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.event.code'))->toBe('api_call');

    // A blank transaction id answers the validation error.
    $response = gqlPost(<<<'GQL'
    query {
        event(code: "api_call") { code }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity');

    // The events index is capped at the 1000-record limit.
    $response = gqlPost(<<<'GQL'
    query {
        events(limit: 5000) { collection { code } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.events.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.events.collection.0.code'))->toBe('api_call');

    // The webhook event-type catalog (config/webhook_event_types.yml).
    $response = gqlPost(<<<'GQL'
    query {
        eventTypes { key name category deprecated }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    $types = collect($response->json('data.eventTypes'));

    expect($types->count())->toBe(75);

    $alert = $types->firstWhere('name', 'alert.triggered');

    expect($alert['key'])->toBe('alert_triggered')
        ->and($alert['category'])->toBe('ALERTS')
        ->and($alert['deprecated'])->toBeFalse();
})->group('ledger:gql:query:event', 'ledger:gql:query:events', 'ledger:gql:query:eventTypes');

it('answers the selectable collections', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    $metric = App\Models\BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api_calls',
        'name' => 'API calls',
    ]);
    $plan = App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'Pro']);

    $response = gqlPost(<<<'GQL'
    query {
        selectableBillableMetrics(limit: 10) { collection { id code name } metadata { totalCount } }
        selectablePlans(limit: 10) { collection { id code name } metadata { totalCount } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.selectableBillableMetrics.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.selectableBillableMetrics.collection.0.code'))->toBe('api_calls')
        ->and($response->json('data.selectablePlans.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.selectablePlans.collection.0.id'))->toBe($plan->id);
})->group('ledger:gql:query:selectableBillableMetrics', 'ledger:gql:query:selectablePlans');

it('answers the subscription and wallet alert fetches', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    $subscriptionAlert = Alert::query()->create([
        'organization_id' => $organization->id,
        'alert_type' => 'current_usage_amount', // SUBSCRIPTION_TYPES
        'subscription_external_id' => 'sub-ext-1',
        'code' => 'alert-sub',
        'direction' => 'increasing',
    ]);
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $wallet = Wallet::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $walletAlert = Alert::query()->create([
        'organization_id' => $organization->id,
        'alert_type' => 'wallet_balance_amount', // WALLET_TYPES
        'wallet_id' => $wallet->id,
        'code' => 'alert-wallet',
        'direction' => 'decreasing',
    ]);

    $response = gqlPost(<<<'GQL'
    query($id: ID!, $externalId: String!, $walletAlertId: ID!, $walletId: String!) {
        subscriptionAlert(id: $id) { id code }
        subscriptionAlerts(subscriptionExternalId: $externalId, limit: 10) { collection { id } metadata { totalCount } }
        walletAlert(id: $walletAlertId) { id code }
        walletAlerts(walletId: $walletId, limit: 10) { collection { id } metadata { totalCount } }
    }
    GQL, [
        'id' => $subscriptionAlert->id,
        'externalId' => 'sub-ext-1',
        'walletAlertId' => $walletAlert->id,
        'walletId' => $wallet->id,
    ], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.subscriptionAlert.code'))->toBe('alert-sub')
        ->and($response->json('data.subscriptionAlerts.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.walletAlert.code'))->toBe('alert-wallet')
        ->and($response->json('data.walletAlerts.metadata.totalCount'))->toBe(1);

    // A wallet-type alert is not reachable through the subscription fetch.
    $response = gqlPost(<<<'GQL'
    query($id: ID!) {
        subscriptionAlert(id: $id) { id }
    }
    GQL, ['id' => $walletAlert->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:subscriptionAlert', 'ledger:gql:query:subscriptionAlerts', 'ledger:gql:query:walletAlert', 'ledger:gql:query:walletAlerts');

it('answers the wallet transaction consumptions and fundings', function (): void {
    [$organization, $user] = gqlAnalyticsSetup();

    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $wallet = Wallet::factory()->for($organization)->create([
        'customer_id' => $customer->id,
        'traceable' => true,
    ]);

    // The consumption edge of an inbound transaction.
    $inbound = WalletTransaction::factory()->create([
        'organization_id' => $organization->id,
        'wallet_id' => $wallet->id,
        'transaction_type' => App\Enums\WalletTransactionType::Inbound,
        'status' => App\Enums\WalletTransactionStatus::Settled,
    ]);
    $outbound = WalletTransaction::factory()->create([
        'organization_id' => $organization->id,
        'wallet_id' => $wallet->id,
        'transaction_type' => App\Enums\WalletTransactionType::Outbound,
        'status' => App\Enums\WalletTransactionStatus::Settled,
    ]);

    App\Models\WalletTransactionConsumption::create([
        'organization_id' => $organization->id,
        'wallet_id' => $wallet->id,
        'inbound_wallet_transaction_id' => $inbound->id,
        'outbound_wallet_transaction_id' => $outbound->id,
        'amount_cents' => 100,
        'consumed_amount_cents' => 100,
    ]);

    $response = gqlPost(<<<'GQL'
    query($txId: ID!) {
        walletTransactionConsumptions(walletTransactionId: $txId, limit: 10) {
            collection { id amountCents creditAmount walletTransaction { id } }
            metadata { totalCount }
        }
    }
    GQL, ['txId' => $inbound->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.walletTransactionConsumptions.metadata.totalCount'))->toBe(1);

    $response = gqlPost(<<<'GQL'
    query($txId: ID!) {
        walletTransactionFundings(walletTransactionId: $txId, limit: 10) {
            collection { id }
            metadata { totalCount }
        }
    }
    GQL, ['txId' => $outbound->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.walletTransactionFundings.metadata.totalCount'))->toBe(1);

    // The directions are mutually exclusive: fundings of an inbound
    // transaction answer the invalid_transaction_type validation.
    $response = gqlPost(<<<'GQL'
    query($txId: ID!) {
        walletTransactionFundings(walletTransactionId: $txId) { collection { id } }
    }
    GQL, ['txId' => $inbound->id], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('unprocessable_entity');

    // An unknown transaction answers not_found.
    $response = gqlPost(<<<'GQL'
    query {
        walletTransactionConsumptions(walletTransactionId: "00000000-0000-0000-0000-000000000000") { collection { id } }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:query:walletTransactionConsumptions', 'ledger:gql:query:walletTransactionFundings');
