<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Integrations\Hubspot\UpdateService;

/**
 * Port of Rails' spec/services/integrations/hubspot/update_service_spec.rb
 * — name/code/targeted object/sync flags updated only for the params keys
 * present; the connection secret never rotates on update.
 */
it('does not update an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($integration->refresh()->name)->not->toBe('Renamed');
});

it('does not update an integration without the hubspot premium integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
});

it('updates the name, code, targeted object and sync flags', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['hubspot']]);
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'Hubspot updated name',
        'code' => 'hubspot_updated',
        'default_targeted_object' => 'companies',
        'sync_invoices' => false,
        'sync_subscriptions' => true,
    ]);

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(HubspotIntegration::class);

    $integration->refresh();

    expect($integration->name)->toBe('Hubspot updated name')
        ->and($integration->code)->toBe('hubspot_updated')
        ->and($integration->defaultTargetedObject())->toBe('companies')
        ->and($integration->syncInvoices())->toBeFalse()
        ->and($integration->syncSubscriptions())->toBeTrue();

    // The connection secret never rotates on update.
    expect($integration->connectionId())->toBe($integration->getFromSecrets('connection_id'));
});

it('returns a validation failure for a blanked name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['hubspot']]);
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

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
