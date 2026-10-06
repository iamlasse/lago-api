<?php

declare(strict_types=1);

namespace App\GraphQL\Unions;

use GraphQL\Type\Definition\Type;
use App\GraphQL\Interfaces\AbstractLagoTypeResolver;
use App\Models\IntegrationCustomer as IntegrationCustomerModel;

/**
 * Type resolver for the frozen SDL's `union IntegrationCustomer` —
 * dispatches on the stored STI type string (Rails'
 * Types::IntegrationCustomers::Object.resolve_type).
 */
class IntegrationCustomer extends AbstractLagoTypeResolver
{
    public function __invoke(mixed $root): Type
    {
        $type = $root instanceof IntegrationCustomerModel ? (string) $root->type : null;

        return match ($type) {
            'IntegrationCustomers::AnrokCustomer' => $this->type('AnrokCustomer'),
            'IntegrationCustomers::AvalaraCustomer' => $this->type('AvalaraCustomer'),
            'IntegrationCustomers::HubspotCustomer' => $this->type('HubspotCustomer'),
            'IntegrationCustomers::NetsuiteCustomer' => $this->type('NetsuiteCustomer'),
            'IntegrationCustomers::SalesforceCustomer' => $this->type('SalesforceCustomer'),
            'IntegrationCustomers::XeroCustomer' => $this->type('XeroCustomer'),
            default => parent::__invoke($root),
        };
    }
}
