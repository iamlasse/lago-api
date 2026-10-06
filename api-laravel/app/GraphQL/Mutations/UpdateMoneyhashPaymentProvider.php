<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Moneyhash::Update
 * (app/graphql/mutations/payment_providers/moneyhash/update.rb): "Update Moneyhash payment provider" —
 * the update variant of the moneyhash create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateMoneyhashPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'moneyhash';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
