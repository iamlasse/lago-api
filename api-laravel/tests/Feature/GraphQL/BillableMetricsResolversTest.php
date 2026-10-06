<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use Illuminate\Support\Str;
use App\Models\BillableMetric;

/**
 * Ports of Rails' spec/graphql/mutations/billable_metrics/{update,destroy}_spec.rb
 * and the billable metric resolvers over the frozen SDL (create is covered by
 * CreateBillableMetricTest).
 *
 * Ledger rows: gql:query:billableMetric, gql:query:billableMetrics,
 * gql:mutation:updateBillableMetric, gql:mutation:destroyBillableMetric.
 */
function gqlBillableMetricsSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const BILLABLE_METRICS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String, $recurring: Boolean, $planId: ID) {
    billableMetrics(page: $page, limit: $limit, searchTerm: $searchTerm, recurring: $recurring, planId: $planId) {
        collection { id code name aggregationType recurring }
        metadata { currentPage totalCount }
    }
}
GQL;

const BILLABLE_METRIC_QUERY = <<<'GQL'
query($id: ID!) {
    billableMetric(id: $id) { id code name }
}
GQL;

const UPDATE_BILLABLE_METRIC_MUTATION = <<<'GQL'
mutation($input: UpdateBillableMetricInput!) {
    updateBillableMetric(input: $input) { id code name }
}
GQL;

const DESTROY_BILLABLE_METRIC_MUTATION = <<<'GQL'
mutation($input: DestroyBillableMetricInput!) {
    destroyBillableMetric(input: $input) { id }
}
GQL;

it('lists billable metrics through billableMetrics with the search term and filters', function (): void {
    [$organization, $user] = gqlBillableMetricsSetup();

    BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Seats',
        'code' => 'seats',
        'recurring' => false,
    ]);

    BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Storage',
        'code' => 'storage',
        'recurring' => true,
    ]);

    $payload = gqlPost(BILLABLE_METRICS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billableMetrics');

    expect(count($payload['collection']))->toBe(2);

    $found = gqlPost(BILLABLE_METRICS_QUERY, ['searchTerm' => 'storage'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billableMetrics');

    expect(count($found['collection']))->toBe(1)
        ->and($found['collection'][0]['code'])->toBe('storage');

    $recurringOnly = gqlPost(BILLABLE_METRICS_QUERY, ['recurring' => true], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billableMetrics');

    expect(count($recurringOnly['collection']))->toBe(1)
        ->and($recurringOnly['collection'][0]['code'])->toBe('storage');
});

it('fetches, updates and destroys a single billable metric', function (): void {
    [$organization, $user] = gqlBillableMetricsSetup();

    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Seats',
        'code' => 'seats',
    ]);

    expect(gqlPost(BILLABLE_METRIC_QUERY, ['id' => $metric->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billableMetric.code'))->toBe('seats');

    // An unknown id answers the not_found error envelope.
    gqlPost(BILLABLE_METRIC_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_BILLABLE_METRIC_MUTATION, ['input' => [
        'id' => $metric->id,
        'name' => 'Renamed seats',
        'code' => 'seats',
        'aggregationType' => 'count_agg',
        'description' => 'Seats metric',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateBillableMetric.name'))
        ->toBe('Renamed seats');

    expect(gqlPost(DESTROY_BILLABLE_METRIC_MUTATION, ['input' => ['id' => $metric->id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyBillableMetric.id'))->toBe($metric->id);

    // A discarded metric no longer resolves.
    gqlPost(BILLABLE_METRIC_QUERY, ['id' => $metric->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
});
