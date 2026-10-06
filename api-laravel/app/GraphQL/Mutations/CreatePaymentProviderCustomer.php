<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\PaymentProviderCustomers\CreateConnectionService;

/**
 * Port of Rails' Mutations::PaymentProviderCustomers::Create
 * (app/graphql/mutations/payment_provider_customers/create.rb): "Creates a
 * payment provider customer connection" — PaymentProviderCustomers\
 * CreateConnectionService with the customer resolved from the organization.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class CreatePaymentProviderCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $customerId = $input['customer_id'] ?? null;
        unset($input['customer_id']);

        // Rails: current_organization.customers.find_by(id: customer_id).
        $customer = $organization->customers()->find($customerId);

        $result = CreateConnectionService::call(customer: $customer, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->payment_provider_customer;
    }
}
