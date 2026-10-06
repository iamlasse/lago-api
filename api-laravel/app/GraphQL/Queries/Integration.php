<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\Integration as IntegrationModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::IntegrationResolver
 * (app/graphql/resolvers/integration_resolver.rb): "Query a single
 * integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:view").
 */
class Integration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): self
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $integration = IntegrationModel::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->find($args['id'] ?? null);

        if ($integration === null) {
            throw Errors::notFoundError('integration');
        }

        return $integration;
    }
}
