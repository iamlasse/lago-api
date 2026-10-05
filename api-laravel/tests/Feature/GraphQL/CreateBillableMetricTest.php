<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\BillableMetric;

/**
 * Port of Rails' Mutations::BillableMetrics::Create over the frozen SDL
 * (spec/graphql/mutations/billable_metrics/create_spec.rb semantics).
 *
 * Ledger rows: gql:mutation:createBillableMetric.
 */
const CREATE_BILLABLE_METRIC_MUTATION = <<<'GQL'
mutation($input: CreateBillableMetricInput!) {
    createBillableMetric(input: $input) {
        id
        code
        name
        description
        aggregationType
        fieldName
        recurring
    }
}
GQL;

it('creates a count-aggregation billable metric through createBillableMetric', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $payload = gqlPost(CREATE_BILLABLE_METRIC_MUTATION, ['input' => [
        'name' => 'New metric',
        'code' => 'new_metric',
        'description' => 'Metric description',
        'aggregationType' => 'count_agg',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createBillableMetric');

    expect($payload['code'])->toBe('new_metric')
        ->and($payload['name'])->toBe('New metric')
        ->and($payload['description'])->toBe('Metric description')
        ->and($payload['aggregationType'])->toBe('count_agg')
        ->and($payload['recurring'])->toBeFalse();

    expect(BillableMetric::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:createBillableMetric');

it('creates a sum-aggregation billable metric with a field name', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    $payload = gqlPost(CREATE_BILLABLE_METRIC_MUTATION, ['input' => [
        'name' => 'Sum metric',
        'code' => 'sum_metric',
        'description' => 'Sum description',
        'aggregationType' => 'sum_agg',
        'fieldName' => 'amount',
        'recurring' => true,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createBillableMetric');

    expect($payload['aggregationType'])->toBe('sum_agg')
        ->and($payload['fieldName'])->toBe('amount')
        ->and($payload['recurring'])->toBeTrue();
})->group('gql:mutation:createBillableMetric');

it('answers a validation error on a duplicated billable metric code', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    BillableMetric::factory()->for($organization)->create(['code' => 'new_metric']);

    $response = gqlPost(CREATE_BILLABLE_METRIC_MUTATION, ['input' => [
        'name' => 'New metric',
        'code' => 'new_metric',
        'description' => 'Metric description',
        'aggregationType' => 'count_agg',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('data.createBillableMetric'))->toBeNull();
})->group('gql:mutation:createBillableMetric');

it('answers a validation error for a custom aggregation without the flag', function (): void {
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    // Rails: custom_agg requires the organization's custom_aggregation flag.
    $response = gqlPost(CREATE_BILLABLE_METRIC_MUTATION, ['input' => [
        'name' => 'Custom metric',
        'code' => 'custom_metric',
        'description' => 'Custom description',
        'aggregationType' => 'custom_agg',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('data.createBillableMetric'))->toBeNull();
})->group('gql:mutation:createBillableMetric');
