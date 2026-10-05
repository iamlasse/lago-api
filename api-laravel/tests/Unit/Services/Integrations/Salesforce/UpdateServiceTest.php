<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\SalesforceIntegration;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Integrations\Salesforce\UpdateService;

/**
 * Port of Rails' spec/services/integrations/salesforce/update_service_spec.rb
 * — name/code/instance_id updated only for the params keys present, behind
 * the organization's premium_integrations flag.
 */
it('does not update an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();
    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($integration->refresh()->name)->not->toBe('Renamed');
});

it('does not update an integration without the salesforce premium integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();
    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
});

it('updates the name, code and instance id', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['salesforce']]);
    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'Salesforce updated name',
        'code' => 'salesforce_updated',
        'instance_id' => 'Instance2',
    ]);

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(SalesforceIntegration::class);

    $integration->refresh();

    expect($integration->name)->toBe('Salesforce updated name')
        ->and($integration->code)->toBe('salesforce_updated')
        ->and($integration->instanceId())->toBe('Instance2');
});

it('returns a validation failure for a blanked name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['salesforce']]);
    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: ['name' => null]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('returns not found without an integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $result = UpdateService::call(integration: null, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});
