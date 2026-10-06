<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Moneyhash::Create
 * (app/graphql/mutations/payment_providers/moneyhash/create.rb): "Add Moneyhash payment provider" —
 * the create variant of the moneyhash create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddMoneyhashPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'moneyhash';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
