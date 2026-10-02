<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Customers\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Customers::Destroy
 * (app/graphql/mutations/customers/destroy.rb): "Delete a Customer" — the
 * payload is `{ id, clientMutationId }` (DestroyCustomerPayload).
 */
class DestroyCustomer
{
    /**
     * @return array{id: ?string, client_mutation_id: ?string}
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.customers.find_by(id:)
        $customer = $organization->customers()->find($args['input']['id'] ?? null);

        $result = DestroyService::call(customer: $customer);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'id' => $result->customer->id,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
