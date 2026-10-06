<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\PaymentProvider as PaymentProviderModel;
use App\GraphQL\Types\PaymentProviders\AbstractProvider;

/**
 * Field resolvers for the frozen SDL's `MoneyhashProvider` type (port of
 * Rails' Types::PaymentProviders::Moneyhash — the settings/secrets
 * accessors).
 */
class MoneyhashProvider extends AbstractProvider
{
    /** Rails: api_key (secrets accessor). */
    public function apiKey(PaymentProviderModel $root): ?string
    {
        $value = $root->apiKey();

        return $value === null ? null : (string) $value;
    }

    /** Rails: flow_id (settings accessor). */
    public function flowId(PaymentProviderModel $root): ?string
    {
        $value = $root->flowId();

        return $value === null || $value === '' ? null : (string) $value;
    }
}
