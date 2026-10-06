<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Flutterwave::Update
 * (app/graphql/mutations/payment_providers/flutterwave/update.rb): "Update Flutterwave payment provider" —
 * the update variant of the flutterwave create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateFlutterwavePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'flutterwave';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
