<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use App\Models\PaymentProvider as PaymentProviderModel;

/**
 * Type resolver for the frozen SDL's `union PaymentProvider` — the provider
 * STI row resolves to the member object matching its `type` slug
 * (PaymentProviders::StripeProvider -> StripeProvider, …).
 */
class PaymentProvider extends AbstractLagoUnionTypeResolver
{
    private const MEMBERS = [
        'AdyenProvider',
        'CashfreeProvider',
        'FlutterwaveProvider',
        'GocardlessProvider',
        'MoneyhashProvider',
        'StripeProvider',
    ];

    public function __invoke(mixed $root): \GraphQL\Type\Definition\Type
    {
        if ($root instanceof PaymentProviderModel) {
            $member = ucfirst((string) PaymentProviderModel::typeToSlug($root->type)).'Provider';

            if (in_array($member, self::MEMBERS, true)) {
                return $this->schemaType($member);
            }
        }

        return parent::__invoke($root);
    }
}
