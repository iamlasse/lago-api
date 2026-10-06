<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\PaymentProvider;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::FlutterwaveService#create_or_update
 * (app/services/payment_providers/flutterwave_service.rb) — find-or-new the
 * organization's flutterwave provider by id/code and assign the args.
 *
 * Rails' FlutterwaveProvider generates its webhook secret before_create —
 * the port mirrors it for a new provider (see
 * PaymentProvider#generateFlutterwaveWebhookSecret).
 */
class FlutterwaveService extends AbstractProviderService
{
    protected function slug(): string
    {
        return 'flutterwave';
    }

    protected function providerAttribute(): string
    {
        return 'flutterwave_provider';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function applyAttributes(PaymentProvider $provider, array $args): void
    {
        if (array_key_exists('secret_key', $args)) {
            $provider->setSecretKey($args['secret_key']);
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

        if (! $provider->exists) {
            $provider->generateFlutterwaveWebhookSecret();
        }
    }
}
