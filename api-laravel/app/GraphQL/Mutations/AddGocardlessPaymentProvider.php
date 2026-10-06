<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Gocardless::Create
 * (app/graphql/mutations/payment_providers/gocardless/create.rb): "Add or update Gocardless payment provider" —
 * the create variant of the gocardless create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddGocardlessPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'gocardless';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
