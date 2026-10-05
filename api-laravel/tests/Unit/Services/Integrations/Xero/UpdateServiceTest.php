<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\XeroIntegration;
use App\Services\Integrations\Xero\UpdateService;

/**
 * Port of Rails' spec/services/integrations/xero/update_service_spec.rb.
 */
it('updates an integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['xero']]);
    $integration = XeroIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'Xero EU',
        'code' => 'xero_eu',
        'sync_invoices' => false,
    ]);

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(XeroIntegration::class);

    $integration->refresh();

    expect($integration->name)->toBe('Xero EU')
        ->and($integration->code)->toBe('xero_eu')
        ->and($integration->syncInvoices())->toBeFalse()
        ->and($integration->syncCreditNotes())->toBeTrue();
});

it('refuses the update without the premium integration flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();
    $integration = XeroIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Xero EU']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
});

it('returns not found for a missing integration', function (): void {
    $result = UpdateService::call(integration: null, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->resource)->toBe('integration');
});

it('returns a validation failure for a blanked name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['xero']]);
    $integration = XeroIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => null]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
