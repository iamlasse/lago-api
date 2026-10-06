<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\Models\IntegrationCustomer;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\IntegrationCustomers\SetAsDefaultService;

/**
 * Port of Rails' Mutations::IntegrationCustomers::SetAsDefault
 * (app/graphql/mutations/integration_customers/set_as_default.rb): "Set an
 * integration connection as the default for its category".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:update").
 */
class SetIntegrationCustomerAsDefault
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $integrationCustomer = IntegrationCustomer::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = SetAsDefaultService::call(integration_customer: $integrationCustomer);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_customer;
    }
}
