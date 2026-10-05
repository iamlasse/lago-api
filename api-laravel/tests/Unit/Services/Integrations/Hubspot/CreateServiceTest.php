<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Queue;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\HubspotIntegration;
use App\Jobs\Integrations\Hubspot\SavePortalIdJob;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Integrations\Hubspot\CreateService;

/**
 * Port of Rails' spec/services/integrations/hubspot/create_service_spec.rb
 * — HubSpot is a premium integration gated by the organization's
 * premium_integrations flag; creation enqueues the portal-id job.
 */
it('does not create an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Hubspot 1',
        code: 'hubspot1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        default_targeted_object: 'companies',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(HubspotIntegration::query()->count())->toBe(0);
});

it('does not create an integration without the hubspot premium integration', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(
        name: 'Hubspot 1',
        code: 'hubspot1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        default_targeted_object: 'companies',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(HubspotIntegration::query()->count())->toBe(0);
});

it('creates an integration with the premium flag and enqueues the portal id job', function (): void {
    config()->set('lago.license', 'premium-token');
    Queue::fake();

    $organization = Organization::factory()->create(['premium_integrations' => ['hubspot']]);

    $result = CreateService::call(
        name: 'Hubspot 1',
        code: 'hubspot1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        default_targeted_object: 'companies',
        sync_invoices: true,
        sync_subscriptions: false,
    );

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(HubspotIntegration::class)
        ->and($result->integration->name)->toBe('Hubspot 1')
        ->and($result->integration->code)->toBe('hubspot1')
        ->and($result->integration->connectionId())->toBe('conn1')
        ->and($result->integration->defaultTargetedObject())->toBe('companies')
        ->and($result->integration->syncInvoices())->toBeTrue()
        ->and($result->integration->syncSubscriptions())->toBeFalse();

    expect(HubspotIntegration::query()->count())->toBe(1);

    Queue::assertPushed(SavePortalIdJob::class, fn (SavePortalIdJob $job) => $job->integration->id === $result->integration->id);
});

it('returns a validation failure for a missing name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['hubspot']]);

    $result = CreateService::call(
        name: null,
        code: 'hubspot1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        default_targeted_object: 'companies',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('returns a validation failure for a missing targeted object', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['hubspot']]);

    $result = CreateService::call(
        name: 'Hubspot 1',
        code: 'hubspot1',
        organization_id: $organization->id,
        connection_id: 'conn1',
        default_targeted_object: null,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['default_targeted_object'])->toBe(['value_is_mandatory']);
});
