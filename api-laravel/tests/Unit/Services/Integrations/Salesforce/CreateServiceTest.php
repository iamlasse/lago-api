<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\SalesforceIntegration;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Integrations\Salesforce\CreateService;

/**
 * Port of Rails' spec/services/integrations/salesforce/create_service_spec.rb
 * — Salesforce is a premium integration gated by the organization's
 * premium_integrations flag.
 */
it('does not create an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Salesforce 1',
        code: 'salesforce',
        organization_id: $organization->id,
        instance_id: 'Instance1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(SalesforceIntegration::query()->count())->toBe(0);
});

it('does not create an integration without the salesforce premium integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Salesforce 1',
        code: 'salesforce',
        organization_id: $organization->id,
        instance_id: 'Instance1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(SalesforceIntegration::query()->count())->toBe(0);
});

it('creates an integration with the premium flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['salesforce']]);

    $result = CreateService::call(
        name: 'Salesforce 1',
        code: 'salesforce',
        organization_id: $organization->id,
        instance_id: 'Instance1',
    );

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(SalesforceIntegration::class)
        ->and($result->integration->name)->toBe('Salesforce 1')
        ->and($result->integration->code)->toBe('salesforce')
        ->and($result->integration->instanceId())->toBe('Instance1');

    expect(SalesforceIntegration::query()->count())->toBe(1);
});

it('returns a validation failure for a missing name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['salesforce']]);

    $result = CreateService::call(
        name: null,
        code: 'salesforce',
        organization_id: $organization->id,
        instance_id: 'Instance1',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('returns a validation failure for a duplicate code', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['salesforce']]);
    SalesforceIntegration::factory()->create(['organization_id' => $organization->id, 'code' => 'salesforce']);

    $result = CreateService::call(
        name: 'Salesforce 2',
        code: 'salesforce',
        organization_id: $organization->id,
        instance_id: 'Instance2',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['code'])->toBe(['value_already_exists']);
});
