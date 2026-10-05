<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.EntraId.AuthorizeService');

use App\Support\CurrentContext;
use App\Models\Integrations\EntraIdIntegration;
use App\Services\Auth\EntraId\AuthorizeService;

/**
 * Port of spec/services/auth/entra_id/authorize_service_spec.rb (Rails).
 */
beforeEach(function (): void {
    CurrentContext::reset();
});

it('returns an authorize url on the tenant host', function (): void {
    $integration = EntraIdIntegration::factory()->create(); // domain foo.test, tenant_id uuid

    $result = AuthorizeService::call(email: 'foo@foo.test');

    expect($result->success())->toBeTrue()
        ->and($result->url)->toContain('https://login.microsoftonline.com/'.$integration->tenantId().'/oauth2/v2.0/authorize')
        ->and($result->url)->toContain('client_id='.$integration->clientId())
        ->and($result->url)->toContain('response_type=code')
        ->and($result->url)->toContain('scope='.urlencode('openid profile email'))
        ->and($result->url)->toContain('redirect_uri='.urlencode('https://app.getlago.com/auth/entra/callback'));
});

it('seeds a single-use state carrying the email', function (): void {
    EntraIdIntegration::factory()->create();

    $result = AuthorizeService::call(email: 'foo@foo.test');

    parse_str((string) parse_url($result->url, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? '';

    expect($state)->not->toBeEmpty()
        ->and(Cache::get($state))->toBe('foo@foo.test');
});

it('returns a failure when the domain is not configured with an integration', function (): void {
    $result = AuthorizeService::call(email: 'foo@bar.com');

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['domain_not_configured']]);
});

it('returns a failure when the invite email differs from the email', function (): void {
    EntraIdIntegration::factory()->create();
    $invite = App\Models\Invite::factory()->create(['email' => 'foo@b.com']);

    $result = AuthorizeService::call(email: 'foo@foo.test', inviteToken: $invite->token);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_email_mismatch']]);
});
