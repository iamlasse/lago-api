<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\GraphQL\Support\Page;
use App\Queries\PaymentMethodsQuery;
use App\Services\PaymentProviderCustomers\Factory;
use App\Models\PaymentProviderCustomer as PaymentProviderCustomerModel;

/**
 * Field resolvers for the frozen SDL's `ProviderCustomer` type (port of
 * Rails' Types::PaymentProviderCustomers::Provider). Plain columns resolve
 * through the snake_case attribute fallback.
 */
class ProviderCustomer
{
    /**
     * Rails: payment_provider — the provider slug behind the connection's
     * STI type; provider-less rows (the STI base) answer nil.
     */
    public function paymentProvider(PaymentProviderCustomerModel $root): ?string
    {
        return Factory::providerSlug($root);
    }

    /** Rails: payment_provider_code — payment_provider&.code (dataloader). */
    public function paymentProviderCode(PaymentProviderCustomerModel $root): ?string
    {
        return $root->paymentProvider?->code;
    }

    /** Rails: provider_payment_methods — the settings-stored methods. */
    public function providerPaymentMethods(PaymentProviderCustomerModel $root): ?array
    {
        return $root->providerPaymentMethods();
    }

    /** Rails: sync_with_provider — the settings flag. */
    public function syncWithProvider(PaymentProviderCustomerModel $root): ?bool
    {
        $sync = $root->getFromSettings('sync_with_provider');

        return $sync === null ? null : (bool) $sync;
    }

    /**
     * Rails: payment_methods — the nested PaymentMethodsResolver of the
     * connection, paginated (kaminari `collection` + `metadata`).
     */
    public function paymentMethods(PaymentProviderCustomerModel $root, mixed $args): Page
    {
        $result = PaymentMethodsQuery::call(
            organization: $root->customer->organization,
            filters: [
                'payment_provider_customer_id' => $root->id,
                'with_deleted' => $args['withDeleted'] ?? null,
            ],
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
        );

        return Page::fromLengthAwarePaginator($result->payment_methods);
    }
}
