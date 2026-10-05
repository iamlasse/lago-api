<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Failures\MethodNotAllowedFailure;
use App\Services\Failures\ValidationFailure;
use App\Models\Integrations\NetsuiteIntegration;
use App\Jobs\Integrations\Aggregator\PerformSyncJob;
use App\Jobs\Integrations\Aggregator\SendRestletEndpointJob;
use App\Services\Integrations\Netsuite\CreateService;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/services/integrations/netsuite/create_service_spec.rb.
 */
function netsuiteCreateArgs(Organization $organization): array
{
    return [
        'name' => 'Netsuite 1',
        'code' => 'netsuite1',
        'organization_id' => $organization->id,
        'connection_id' => 'conn1',
        'client_id' => 'cl1',
        'client_secret' => 'secret',
        'token_id' => 'xyz',
        'token_secret' => 'zyx',
        'account_id' => 'Acc 1',
        'script_endpoint_url' => 'https://restlets.example.com/script',
    ];
}

it('does not create an integration without a premium license', function (): void {
    config(['lago.license' => null]);

    $organization = Organization::factory()->create();

    $result = CreateService::call(params: netsuiteCreateArgs($organization));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(NetsuiteIntegration::query()->count())->toBe(0);
});

it('does not create an integration without the netsuite premium integration flag', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create();

    $result = CreateService::call(params: netsuiteCreateArgs($organization));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);

    expect(NetsuiteIntegration::query()->count())->toBe(0);
});

it('creates an integration and enqueues the restlet + sync jobs', function (): void {
    Queue::fake();

    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['netsuite']]);

    $result = CreateService::call(params: netsuiteCreateArgs($organization));

    expect($result->failure())->toBeFalse()
        ->and($result->integration)->toBeInstanceOf(NetsuiteIntegration::class);

    $integration = $result->integration;

    expect($integration->name)->toBe('Netsuite 1')
        ->and($integration->tokenId())->toBe('xyz')
        ->and($integration->tokenSecret())->toBe('zyx')
        ->and($integration->clientSecret())->toBe('secret')
        ->and($integration->scriptEndpointUrl())->toBe('https://restlets.example.com/script')
        // Rails: the account_id= override normalizes into settings.
        ->and($integration->accountId())->toBe('acc-1');

    expect(NetsuiteIntegration::query()->count())->toBe(1);

    Queue::assertPushed(SendRestletEndpointJob::class);
    Queue::assertPushed(PerformSyncJob::class);
});

it('returns a validation failure for a missing name', function (): void {
    config()->set('lago.license', 'premium-token');

    $organization = Organization::factory()->create(['premium_integrations' => ['netsuite']]);

    $args = netsuiteCreateArgs($organization);
    $args['name'] = null;

    $result = CreateService::call(params: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});
