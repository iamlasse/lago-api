<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\NotFoundFailure;
use App\Models\Integrations\AvalaraIntegration;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Integrations\Avalara\UpdateService;

/**
 * Port of Rails' spec/services/integrations/avalara/update_service_spec.rb.
 */
it('returns not found without an integration', function (): void {
    $result = UpdateService::call(integration: null, params: ['name' => 'X']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('does not update without the avalara premium flag', function (): void {
    $integration = AvalaraIntegration::factory()->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => 'New name']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
});

it('updates the name, code and company code', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['avalara']]);
    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'New name',
        'code' => 'new_code',
        'company_code' => 'NEW_COMPANY',
    ]);

    expect($result->failure())->toBeFalse();

    $integration->refresh();

    expect($integration->name)->toBe('New name')
        ->and($integration->code)->toBe('new_code')
        ->and($integration->companyCode())->toBe('NEW_COMPANY');
});
