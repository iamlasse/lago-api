<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use App\Services\CustomerPortal\CustomerUpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CustomerPortal::UpdateCustomer
 * (app/graphql/mutations/customer_portal/update_customer.rb): "Update
 * customer data from Customer Portal" — the portal customer's own record,
 * through the CustomerPortal\CustomerUpdateService port.
 */
class UpdateCustomerPortalCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $result = CustomerUpdateService::call(
            customer: $customer,
            args: Args::snakeKeys(Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->customer;
    }
}
