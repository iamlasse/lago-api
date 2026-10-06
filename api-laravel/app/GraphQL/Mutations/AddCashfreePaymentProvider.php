<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Cashfree::Create
 * (app/graphql/mutations/payment_providers/cashfree/create.rb): "Add or update Cashfree payment provider" —
 * the create variant of the cashfree create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddCashfreePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'cashfree';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
