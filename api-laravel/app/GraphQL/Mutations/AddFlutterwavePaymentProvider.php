<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Flutterwave::Create
 * (app/graphql/mutations/payment_providers/flutterwave/create.rb): "Add Flutterwave payment provider" —
 * the create variant of the flutterwave create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddFlutterwavePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'flutterwave';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
