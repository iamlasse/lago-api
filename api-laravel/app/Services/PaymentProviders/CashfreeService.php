<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Models\PaymentProvider;

use function array_key_exists;

/**
 * Port of Rails' PaymentProviders::CashfreeService#create_or_update
 * (app/services/payment_providers/cashfree_service.rb) — find-or-new the
 * organization's cashfree provider by id/code and assign the args (client_id
 * and client_secret live in secrets).
 */
class CashfreeService extends AbstractProviderService
{
    protected function slug(): string
    {
        return 'cashfree';
    }

    protected function providerAttribute(): string
    {
        return 'cashfree_provider';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function applyAttributes(PaymentProvider $provider, array $args): void
    {
        if (array_key_exists('client_id', $args)) {
            $this->pushToSecrets($provider, 'client_id', $args['client_id']);
        }

        if (array_key_exists('client_secret', $args)) {
            $this->pushToSecrets($provider, 'client_secret', $args['client_secret']);
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
