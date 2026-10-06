<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

/**
 * Port of Rails' Mutations::PaymentProviders::Stripe::Update
 * (app/graphql/mutations/payment_providers/stripe/update.rb): "Update Stripe payment provider" —
 * the update variant of the stripe create-or-update.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update") lands with the
 * roles/Permission slice (context permissions are not populated yet).
 */
class UpdateStripePaymentProvider extends AbstractAddUpdatePaymentProvider
{
    protected function slug(): string
    {
        return 'stripe';
    }

    protected function requiredPermission(): string
    {
        return 'organization:integrations:update';
    }
}
