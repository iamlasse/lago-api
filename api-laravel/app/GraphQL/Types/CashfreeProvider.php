<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `CashfreeProvider` type (port of
 * Rails' Types::PaymentProviders::Cashfree — the secrets accessors).
 */
class CashfreeProvider extends AbstractProvider
{
    /** Rails: client_id (secrets accessor). */
    public function clientId(PaymentProviderModel $root): ?string
    {
        $value = $root->clientId();

        return $value === null ? null : (string) $value;
    }

    /** Rails: client_secret (secrets accessor). */
    public function clientSecret(PaymentProviderModel $root): ?string
    {
        $value = $root->clientSecret();

        return $value === null ? null : (string) $value;
    }
}
