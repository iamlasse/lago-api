<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Adyen::Create
 * (app/graphql/mutations/payment_providers/adyen/create.rb): "Add Adyen payment provider" —
 * the create variant of the adyen create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddAdyenPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'adyen';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
