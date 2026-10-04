<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Models\AddOn as AddOnModel;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::AddOnResolver#add_on
 * (app/graphql/resolvers/add_on_resolver.rb): "Query a single add-on of an
 * organization" — current_organization.add_ons.find(id); an unknown id
 * answers with the not_found envelope.
 */
class AddOn
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?AddOnModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.add_ons.find(id) — a discarded add-on
        // no longer resolves (the default kept scope).
        $found = AddOnModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('add_on');
        }

        return $found;
    }
}
