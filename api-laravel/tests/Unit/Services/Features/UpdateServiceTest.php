<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Services\Features\UpdateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Port of Rails' spec/services/entitlement/feature_update_service_spec.rb.
 */
function updateFeature(Organization $organization, array $featureAttributes = [], array $privileges = []): Feature
{
    unset($organization);

    $feature = Feature::factory()->create($featureAttributes);

    foreach ($privileges as $privilege) {
        Privilege::factory()->forFeature($feature)->create($privilege);
    }

    return $feature;
}

it('updates the feature attributes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, [
        'code' => 'seats',
        'name' => 'Feature',
        'description' => 'Feature description',
    ]);

    $result = UpdateService::call(
        feature: $feature,
        params: ['name' => 'New name', 'description' => 'New description'],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($result->feature->name)->toBe('New name')
        ->and($result->feature->description)->toBe('New description');
})->group('ledger:svc:Features.UpdateService');

it('does not change attributes missing from a full update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats', 'name' => 'Feature']);

    $result = UpdateService::call(feature: $feature, params: ['description' => 'D'], partial: false);

    expect($result->success())->toBeTrue()
        ->and($result->feature->name)->toBe('Feature')
        ->and($result->feature->description)->toBe('D');
});

it('creates a privilege on update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats']);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [['code' => 'max_admins', 'value_type' => 'integer']]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(Privilege::count())->toBe(1)
        ->and($feature->privileges()->get()->sole()->code)->toBe('max_admins')
        ->and($feature->privileges()->get()->sole()->value_type)->toBe('integer');
});

it('unions the select options on update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'sso'], [
        ['code' => 'provider', 'value_type' => 'select', 'config' => ['select_options' => ['okta']]],
    ]);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [[
            'code' => 'provider',
            'config' => ['select_options' => ['okta', 'google']],
        ]]],
        partial: false,
    );

    expect($result->success())->toBeTrue();

    $config = $feature->privileges->sole()->config;

    expect($config['select_options'])->toBe(['okta', 'google']);
});

it('discards privileges missing from a full update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats'], [
        ['code' => 'max_admins', 'value_type' => 'integer'],
        ['code' => 'max', 'value_type' => 'integer'],
    ]);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [['code' => 'max_admins', 'value_type' => 'integer']]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($feature->privileges()->count())->toBe(1)
        ->and($feature->privileges()->sole()->code)->toBe('max_admins')
        // The discarded privilege's entitlement values are discarded with it.
        ->and(App\Models\EntitlementValue::query()->count())->toBe(0);
});

it('keeps privileges missing from a partial update', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats'], [
        ['code' => 'max_admins', 'value_type' => 'integer'],
        ['code' => 'max', 'value_type' => 'integer'],
    ]);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [['code' => 'max_admins', 'value_type' => 'integer']]],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and($feature->privileges()->count())->toBe(2);
});

it('returns a validation failure on a duplicated privilege code', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats']);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [['code' => 'a'], ['code' => 'a']]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['privilege.code'])->toBe(['value_is_duplicated']);
});

it('returns a validation failure on an invalid privilege value type', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $feature = updateFeature($organization, ['code' => 'seats']);

    $result = UpdateService::call(
        feature: $feature,
        params: ['privileges' => [['code' => 'max_admins', 'value_type' => 'invalid_type']]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['privilege.value_type'])->toBe(['value_is_invalid']);
});

it('returns a not found failure when the feature is missing', function (): void {
    $result = UpdateService::call(feature: null, params: [], partial: false);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('feature');
});
