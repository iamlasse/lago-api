<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\Integrations\OktaIntegration;
use App\Services\Integrations\Okta\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Okta::Update
 * (app/graphql/mutations/integrations/okta/update.rb): "Update Okta
 * integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 */
class UpdateOktaIntegration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = \App\GraphQL\Support\Args::snakeKeys(\App\GraphQL\Support\Args::input($args));

        // Rails: current_organization.integrations.find_by(id:).
        $integration = OktaIntegration::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['id'] ?? null)
            ->first();

        $result = UpdateService::call(integration: $integration, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration;
    }
}
