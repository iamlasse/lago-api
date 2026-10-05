<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\NetsuiteIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;
use App\Jobs\Integrations\Aggregator\SendRestletEndpointJob;
use App\Services\Integrations\Netsuite\UpdateService;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/services/integrations/netsuite/update_service_spec.rb.
 */
it('updates an integration and re-registers a changed restlet endpoint', function (): void {
    Queue::fake();

    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['netsuite']]);
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: [
        'name' => 'Netsuite 1',
        'code' => 'netsuite1',
        'script_endpoint_url' => 'https://restlets.example.com/new-script',
    ]);

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(NetsuiteIntegration::class);

    $integration->refresh();

    expect($integration->name)->toBe('Netsuite 1')
        ->and($integration->code)->toBe('netsuite1')
        ->and($integration->scriptEndpointUrl())->toBe('https://restlets.example.com/new-script');

    Queue::assertPushed(SendRestletEndpointJob::class);
    Queue::assertPushed(PerformSyncJob::class);
});

it('does not re-register an unchanged restlet endpoint', function (): void {
    Queue::fake();

    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['netsuite']]);
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeFalse()
        ->and($integration->refresh()->name)->toBe('Renamed');

    Queue::assertNotPushed(SendRestletEndpointJob::class);
    Queue::assertNotPushed(PerformSyncJob::class);
});

it('refuses the update without the premium integration flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => 'Renamed']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
});

it('returns a validation failure for a blanked name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['netsuite']]);
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    $result = UpdateService::call(integration: $integration, params: ['name' => null]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
