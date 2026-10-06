<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Adyen::Update
 * (app/graphql/mutations/payment_providers/adyen/update.rb): "Update Adyen payment provider" —
 * the update variant of the adyen create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateAdyenPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'adyen';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
