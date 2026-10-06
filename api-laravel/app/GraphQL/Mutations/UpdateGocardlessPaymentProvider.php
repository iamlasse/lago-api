<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Gocardless::Update
 * (app/graphql/mutations/payment_providers/gocardless/update.rb): "Update Gocardless payment provider" —
 * the update variant of the gocardless create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateGocardlessPaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'gocardless';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
