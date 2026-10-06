<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Customer;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\IntegrationCustomers\CreateConnectionService;

/**
 * Port of Rails' Mutations::IntegrationCustomers::Create
 * (app/graphql/mutations/integration_customers/create.rb): "Creates an
 * integration customer connection".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update").
 */
class CreateIntegrationCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.customers.find_by(id: customer_id).
        $customer = Customer::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['customerId'] ?? null)
            ->first();

        $result = CreateConnectionService::call(
            customer: $customer,
            params: \App\GraphQL\Support\Args::snakeKeys($input),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_customer;
    }
}
