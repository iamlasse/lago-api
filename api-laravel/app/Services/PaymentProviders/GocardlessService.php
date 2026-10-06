<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use Illuminate\Support\Str;
use App\Models\PaymentProvider;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::GocardlessService#create_or_update
 * (app/services/payment_providers/gocardless_service.rb) — find-or-new the
 * organization's gocardless provider by id/code and assign the args.
 *
 * TODO(port): the access_code -> access_token OAuth2 exchange
 * (OAuth2::Client against GOCARDLESS_CLIENT_ID/GOCARDLESS_CLIENT_SECRET and
 * the LAGO_OAUTH_PROXY_URL redirect) — no OAuth2 client is ported yet, so a
 * present access_code cannot be exchanged and the stored token is left
 * untouched.
 */
class GocardlessService extends AbstractProviderService
{
    protected function slug(): string
    {
        return 'gocardless';
    }

    protected function providerAttribute(): string
    {
        return 'gocardless_provider';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function applyAttributes(PaymentProvider $provider, array $args): void
    {
        // TODO(port): exchange args['access_code'] for the access token like
        // Rails (oauth.auth_code.get_token(access_code, redirect_uri:)).

        if (($provider->webhookSecret() ?? '') === '') {
            // Rails: webhook_secret = SecureRandom.alphanumeric(50) when blank.
            $provider->setWebhookSecret(Str::random(50));
        }

        if (array_key_exists('success_redirect_url', $args)) {
            $provider->setSuccessRedirectUrl($args['success_redirect_url']);
        }

        if (array_key_exists('code', $args)) {
            $provider->code = $args['code'];
        }

        if (array_key_exists('name', $args)) {
            $provider->name = $args['name'];
        }
    }
}
