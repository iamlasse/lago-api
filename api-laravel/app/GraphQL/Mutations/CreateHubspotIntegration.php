<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Integrations\Hubspot\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Hubspot::Create
 * (app/graphql/mutations/integrations/hubspot/create.rb): "Create Hubspot
 * integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:create").
 */
class CreateHubspotIntegration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(
            user: LagoContext::currentUser($context),
            name: $input['name'] ?? null,
            code: $input['code'] ?? null,
            organization_id: $organization->id,
            connection_id: $input['connection_id'] ?? null,
            default_targeted_object: $input['default_targeted_object'] ?? null,
            sync_invoices: $input['sync_invoices'] ?? null,
            sync_subscriptions: $input['sync_subscriptions'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration;
    }
}
