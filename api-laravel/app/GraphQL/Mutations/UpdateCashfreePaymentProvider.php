<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Cashfree::Update
 * (app/graphql/mutations/payment_providers/cashfree/update.rb): "Update Cashfree payment provider" —
 * the update variant of the cashfree create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateCashfreePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'cashfree';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
