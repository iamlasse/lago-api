<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use App\Models\Integrations\XeroIntegration;
use App\Models\Integrations\NetsuiteIntegration;
use App\Services\Integrations\Aggregator\SyncService;
use App\Services\Integrations\Aggregator\SendRestletEndpointService;

/**
 * Ports of Rails' spec/services/integrations/aggregator/sync_service_spec.rb
 * and send_restlet_endpoint_service_spec.rb — the Nango sync trigger and the
 * Netsuite restlet endpoint registration, over Http::fake.
 */
beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('triggers the xero syncs', function (): void {
    $integration = XeroIntegration::factory()->create();

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/sync/trigger') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response('{}');
    });

    $result = SyncService::call(integration: $integration);

    expect($result->success())->toBeTrue();

    expect($captured->header('Connection-Id'))->toBe([$integration->connectionId()])
        ->and($captured->header('Authorization'))->toBe(['Bearer secret']);

    expect($captured->data())->toBe([
        'provider_config_key' => 'xero',
        'syncs' => ['xero-accounts-sync', 'xero-items-sync', 'xero-contacts-sync'],
    ]);
});

it('triggers the netsuite subsidiaries sync', function (): void {
    $integration = NetsuiteIntegration::factory()->create();

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/sync/trigger') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response('{}');
    });

    $result = SyncService::call(integration: $integration);

    expect($result->success())->toBeTrue()
        ->and($captured->data())->toBe([
            'provider_config_key' => 'netsuite-tba',
            'syncs' => ['netsuite-subsidiaries-sync'],
        ]);
});

it('registers the netsuite restlet endpoint on the connection metadata', function (): void {
    $integration = NetsuiteIntegration::factory()->create([
        'settings' => array_merge(
            NetsuiteIntegration::factory()->definition()['settings'],
            ['script_endpoint_url' => 'https://restlets.example.com/script'],
        ),
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured, $integration) {
        if ($request->url() !== 'https://api.nango.dev/connection/'.$integration->connectionId().'/metadata') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response('{}');
    });

    $result = SendRestletEndpointService::call(integration: $integration);

    expect($result->success())->toBeTrue()
        ->and($captured->header('Provider-Config-Key'))->toBe(['netsuite-tba'])
        ->and($captured->data())->toBe(['restletEndpoint' => 'https://restlets.example.com/script']);
});

it('skips the restlet registration without a script endpoint url', function (): void {
    $integration = NetsuiteIntegration::factory()->create([
        'settings' => ['script_endpoint_url' => null],
    ]);

    Http::fake();

    $result = SendRestletEndpointService::call(integration: $integration);

    expect($result->success())->toBeTrue();

    Http::assertNothingSent();
});

it('skips the restlet registration for non-netsuite integrations', function (): void {
    $integration = XeroIntegration::factory()->create();

    Http::fake();

    $result = SendRestletEndpointService::call(integration: $integration);

    expect($result->success())->toBeTrue();

    Http::assertNothingSent();
});
