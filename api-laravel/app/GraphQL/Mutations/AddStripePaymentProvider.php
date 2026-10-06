<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Stripe::Create
 * (app/graphql/mutations/payment_providers/stripe/create.rb): "Add Stripe API keys to the organization" —
 * the create variant of the stripe create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class AddStripePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'stripe';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:create';
    }
}
