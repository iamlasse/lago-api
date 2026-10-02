<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Customers\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Customers::Update
 * (app/graphql/mutations/customers/update.rb): "Updates an existing
 * Customer" — the `id` stays in the args passed to the service, exactly like
 * Rails (`Customers::UpdateService.call(customer:, args:)`).
 */
class UpdateCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id: args[:id])
        $customer = $organization->customers()->find($input['id'] ?? null);

        $result = UpdateService::call(
            customer: $customer,
            args: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->customer;
    }
}
