<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Support\License;
use App\Models\Customer as CustomerModel;

/**
 * Field resolvers for the frozen SDL's `CustomerPortalCustomer` type (port of
 * Rails' Types::CustomerPortal::Customers::Object). Extends the regular
 * customer type (display_name / applicable_timezone / the shipping_address
 * hash resolve the same); the portal type carries its own trimmed
 * billing configuration and drops every organization-scoped field.
 */
class CustomerPortalCustomer extends Customer
{
    /** Rails: the account_type/customer_type enum names — raw column values. */
    public function accountType(CustomerModel $root): ?string
    {
        return $root->getRawOriginal('account_type');
    }

    public function customerType(CustomerModel $root): ?string
    {
        return $root->getRawOriginal('customer_type');
    }

    /** Rails: License.premium? — a license-level flag, not a customer column. */
    public function premium(CustomerModel $root): bool
    {
        return License::premium();
    }

    /**
     * Rails: Types::CustomerPortal::Customers::Object#billing_configuration —
     * the trimmed portal variant (no issuing-date fields).
     *
     * @return array{id: string, document_locale: ?string}
     */
    public function billingConfiguration(CustomerModel $root): array
    {
        return [
            'id' => $root->id.'-c0nf',
            'document_locale' => $root->document_locale,
        ];
    }

    /**
     * Rails: Types::CustomerPortal::Customers::Object#billing_entity_billing_configuration.
     *
     * @return array{id: ?string, document_locale: ?string}
     */
    public function billingEntityBillingConfiguration(CustomerModel $root): array
    {
        return [
            'id' => $root->billing_entity_id.'-c1nf',
            'document_locale' => $root->billingEntity?->document_locale,
        ];
    }
}
