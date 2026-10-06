<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\CustomerPortal\GenerateUrlService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CustomerPortal::GenerateUrl
 * (app/graphql/mutations/customer_portal/generate_url.rb): "Generate
 * customer portal URL" — an ADMIN-API mutation (AuthenticableApiUser +
 * RequiredOrganization, NOT the portal guard): the organization's customer is
 * minted a 12h portal token through Customers\GenerateUrlService
 * (CustomerPortal::GenerateUrlService in Rails).
 */
class GenerateCustomerPortalUrl
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id:).
        $customer = $organization->customers()->find($input['id'] ?? null);

        $result = GenerateUrlService::call(customer: $customer);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'url' => $result->url,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
