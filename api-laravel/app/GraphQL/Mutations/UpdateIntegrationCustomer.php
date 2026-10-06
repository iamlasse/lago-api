<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Models\IntegrationCustomer;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\IntegrationCustomers\UpdateConnectionService;

/**
 * Port of Rails' Mutations::IntegrationCustomers::Update
 * (app/graphql/mutations/integration_customers/update.rb): "Updates an
 * integration customer connection".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update").
 */
class UpdateIntegrationCustomer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: IntegrationCustomers::BaseCustomer.where(organization_id:)
        // .find_by(id:) — the port queries the base table directly (the STI
        // subclasses narrow their own type).
        $integrationCustomer = IntegrationCustomer::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = UpdateConnectionService::call(
            integration_customer: $integrationCustomer,
            params: \App\GraphQL\Support\Args::snakeKeys($input),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_customer;
    }
}
