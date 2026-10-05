<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Services\Features\CreateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Port of Rails' spec/services/entitlement/feature_create_service_spec.rb.
 */
function createFeatureParams(Organization $organization, array $overrides = []): array
{
    unset($organization);

    return [
        'code' => 'seats',
        'name' => 'Number of seats',
        'description' => 'Number of users of the account',
        'privileges' => [
            ['code' => 'max_admins', 'value_type' => 'integer'],
            ['code' => 'max', 'name' => 'Maximum', 'value_type' => 'integer'],
        ],
        ...$overrides,
    ];
}

it('creates a feature with the provided attributes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(
        organization: $organization,
        params: createFeatureParams($organization),
    );

    expect($result->success())->toBeTrue()
        ->and(Feature::count())->toBe(1)
        ->and($result->feature->code)->toBe('seats')
        ->and($result->feature->name)->toBe('Number of seats')
        ->and($result->feature->description)->toBe('Number of users of the account')
        ->and($result->feature->organization_id)->toBe($organization->id);
})->group('ledger:svc:Entitlement.FeatureCreateService');

it('trims codes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'code' => '  seats  ',
        'privileges' => [['code' => '  test ']],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->feature->code)->toBe('seats')
        ->and($result->feature->privileges)->toHaveCount(1)
        ->and($result->feature->privileges->first()->code)->toBe('test');
});

it('creates privileges for the feature', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization));

    expect($result->success())->toBeTrue()
        ->and(Privilege::count())->toBe(2);

    $privileges = $result->feature->privileges;

    $maxAdmins = $privileges->firstWhere('code', 'max_admins');
    expect($maxAdmins->value_type)->toBe('integer')
        ->and($maxAdmins->name)->toBeNull();

    $max = $privileges->firstWhere('code', 'max');
    expect($max->value_type)->toBe('integer')
        ->and($max->name)->toBe('Maximum');
});

it('returns a not found failure when the organization is missing', function (): void {
    $result = CreateService::call(organization: null, params: createFeatureParams(
        Organization::factory()->make(),
    ));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('organization');
});

it('returns a validation failure when the feature code is empty', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'code' => '',
        'privileges' => [],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['code'])->toBe(['value_is_mandatory']);
});

it('returns a validation failure when the feature code already exists', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['code'])->toBe(['value_already_exist']);
});

it('defaults the privilege value type to string', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'privileges' => [['code' => 'max_admins']],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->feature->privileges->sole()->value_type)->toBe('string');
});

it('returns a validation failure when the privilege code is duplicated', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'privileges' => [
            ['code' => 'max_admins'],
            ['code' => 'max_admins'],
        ],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['privilege.code'])->toBe(['value_is_duplicated']);
});

it('returns a validation failure when the privilege value type is invalid', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'privileges' => [['code' => 'max_admins', 'value_type' => 'invalid_type']],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['privilege.value_type'])->toBe(['value_is_invalid']);
});

it('returns a validation failure when the privilege code is empty', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'privileges' => [['value_type' => 'integer']],
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['privilege.code'])->toBe(['value_is_mandatory']);
});

it('creates a feature without privileges', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'privileges' => [],
    ]));

    expect($result->success())->toBeTrue()
        ->and(Feature::count())->toBe(1)
        ->and(Privilege::count())->toBe(0)
        ->and($result->feature->privileges)->toHaveCount(0);
});

it('creates a feature with only the required attributes', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'name' => null,
        'description' => null,
        'privileges' => [['code' => 'max_admins', 'value_type' => 'integer']],
    ]));

    expect($result->success())->toBeTrue()
        ->and($result->feature->code)->toBe('seats')
        ->and($result->feature->name)->toBeNull()
        ->and($result->feature->description)->toBeNull();
});

it('creates a privilege with select config', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(organization: $organization, params: createFeatureParams($organization, [
        'code' => 'sso',
        'privileges' => [[
            'code' => 'provider',
            'name' => 'Provider Name',
            'value_type' => 'select',
            'config' => ['select_options' => ['okta', 'ad', 'google', 'custom']],
        ]],
    ]));

    expect($result->success())->toBeTrue()
        ->and(Privilege::count())->toBe(1);

    $privilege = $result->feature->privileges->first();

    expect($privilege->code)->toBe('provider')
        ->and($privilege->name)->toBe('Provider Name')
        ->and($privilege->value_type)->toBe('select')
        ->and($privilege->config)->toBe(['select_options' => ['okta', 'ad', 'google', 'custom']]);
});
