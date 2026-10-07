<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use App\Models\Integrations\HubspotIntegration;
use App\Services\Integrations\Hubspot\SavePortalIdService;

/**
 * Port of Rails' spec/services/integrations/hubspot/
 * save_portal_id_service_spec.rb — the Nango account-information fetch over
 * Http::fake, storing the account id as the portal id once.
 */
beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    Env::getRepository()->clear('NANGO_SECRET_KEY');

    unset($_ENV['NANGO_SECRET_KEY']);
});

it('saves the portal id from the account information', function (): void {
    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/account-information') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(
            (string) file_get_contents(base_path('tests/fixtures/IntegrationAggregator/hubspot/account_information_response.json')),
        );
    });

    $result = SavePortalIdService::call(integration: $integration);

    expect($result->failure())->toBeFalse()
        ->and($integration->refresh()->portalId())->toBe('1234567890');

    expect($captured->header('Connection-Id'))->toBe([$integration->getFromSecrets('connection_id')])
        ->and($captured->header('Provider-Config-Key'))->toBe(['hubspot'])
        ->and($captured->header('Authorization'))->toBe(['Bearer secret']);
});

it('keeps the portal id when it is already present', function (): void {
    $organization = Organization::factory()->create();
    $integration = HubspotIntegration::factory()->create([
        'organization_id' => $organization->id,
        'settings' => ['portal_id' => 'already-there'],
    ]);

    Http::fake(fn () => Http::response('', 500));

    $result = SavePortalIdService::call(integration: $integration);

    expect($result->failure())->toBeFalse()
        ->and($integration->refresh()->portalId())->toBe('already-there');
});
