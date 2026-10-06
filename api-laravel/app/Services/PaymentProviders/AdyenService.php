<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\PaymentProvider;

/**
 * Port of Rails' PaymentProviders::AdyenService#create_or_update
 * (app/services/payment_providers/adyen_service.rb) — find-or-new the
 * organization's adyen provider by id/code and assign the args (api_key and
 * hmac_key live in secrets; live_prefix and merchant_account in settings).
 *
 * Unlike Stripe there is no webhook provisioning tail — only the shared
 * code-change customer propagation from PaymentProviders::BaseService.
 */
class AdyenService extends AbstractProviderService
{
    protected function slug(): string
    {
        return 'adyen';
    }

    protected function providerAttribute(): string
    {
        return 'adyen_provider';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function applyAttributes(PaymentProvider $provider, array $args): void
    {
        if (array_key_exists('api_key', $args)) {
            $this->pushToSecrets($provider, 'api_key', $args['api_key']);
        }

        if (array_key_exists('code', $args)) {
            $provider->code = $args['code'];
        }

        if (array_key_exists('name', $args)) {
            $provider->name = $args['name'];
        }

        if (array_key_exists('merchant_account', $args)) {
            $provider->pushToSettings('merchant_account', $args['merchant_account']);
        }

        if (array_key_exists('live_prefix', $args)) {
            $provider->pushToSettings('live_prefix', $args['live_prefix']);
        }

        if (array_key_exists('hmac_key', $args)) {
            $this->pushToSecrets($provider, 'hmac_key', $args['hmac_key']);
        }

        if (array_key_exists('success_redirect_url', $args)) {
            $provider->setSuccessRedirectUrl($args['success_redirect_url']);
        }
    }
}
