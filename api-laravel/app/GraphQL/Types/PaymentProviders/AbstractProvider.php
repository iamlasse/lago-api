<?php

declare(strict_types=1);

namespace App\GraphQL\Types\PaymentProviders;

use App\Models\PaymentProvider as PaymentProviderModel;

/**
 * Shared accessor shape of the frozen SDL's `PaymentProvider` union member
 * types (port of Rails' Types::PaymentProviders::{Stripe,Adyen,Cashfree,
 * Flutterwave,Gocardless,Moneyhash} objects). Fields backed by a plain
 * column resolve through the snake_case attribute fallback; everything
 * stored in settings/secrets resolves through these accessors.
 */
abstract class AbstractProvider
{
    public function __construct(protected readonly PaymentProviderModel $provider) {}

    /** Rails: success_redirect_url — the settings-stored redirect. */
    public function successRedirectUrl(PaymentProviderModel $root): ?string
    {
        $value = $root->successRedirectUrl();

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
