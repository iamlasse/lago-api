<?php

declare(strict_types=1);

uses()->group('ledger:svc:Auth.Okta.AuthorizeService');

use App\Models\Invite;
use App\Support\CurrentContext;
use App\Models\Integrations\OktaIntegration;
use App\Services\Auth\Okta\AuthorizeService;

/**
 * Port of spec/services/auth/okta/authorize_service_spec.rb (Rails).
 */
beforeEach(function (): void {
    CurrentContext::reset();
});

it('returns an authorize url', function (): void {
    $integration = OktaIntegration::factory()->create(); // domain foo.test, org "Foobar"

    $result = AuthorizeService::call(email: 'foo@foo.test');

    expect($result->success())->toBeTrue()
        ->and($result->url)->toContain('https://foobar.okta.com/oauth2/v1/authorize')
        ->and($result->url)->toContain('client_id='.$integration->clientId())
        ->and($result->url)->toContain('response_type=code')
        ->and($result->url)->toContain('scope='.urlencode('openid profile email'))
        ->and($result->url)->toContain('redirect_uri='.urlencode('https://app.getlago.com/auth/okta/callback'));
});

it('seeds a single-use state carrying the email', function (): void {
    $integration = OktaIntegration::factory()->create();

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

it('returns an authorize url with an invite token', function (): void {
    $integration = OktaIntegration::factory()->create();
    $invite = Invite::factory()->create(['email' => 'foo@foo.test']);

    $result = AuthorizeService::call(email: 'foo@foo.test', inviteToken: $invite->token);

    expect($result->success())->toBeTrue()
        ->and($result->url)->toContain('https://foobar.okta.com/oauth2/v1/authorize');
});

it('returns a failure when the invite email differs from the email', function (): void {
    OktaIntegration::factory()->create();
    $invite = Invite::factory()->create(['email' => 'foo@b.com']);

    $result = AuthorizeService::call(email: 'foo@foo.test', inviteToken: $invite->token);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_email_mismatch']]);
});

it('returns a failure when the pending invite does not exist', function (): void {
    OktaIntegration::factory()->create();
    $invite = Invite::factory()->accepted()->create(['email' => 'foo@foo.test']);

    $result = AuthorizeService::call(email: 'foo@foo.test', inviteToken: $invite->token);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['invite_not_found']]);
});
