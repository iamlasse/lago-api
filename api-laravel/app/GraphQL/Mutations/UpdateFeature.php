<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Feature;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Features\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Entitlement::UpdateFeature
 * (app/graphql/mutations/entitlement/update_feature.rb): "Updates an
 * existing feature" — a FULL update (partial: false): privileges missing
 * from the input are discarded.
 */
class UpdateFeature
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.features.find_by(id: args[:id]) —
        // nil reaches the service for the not_found envelope.
        $feature = Feature::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(
            feature: $feature,
            params: [
                'name' => $input['name'] ?? null,
                'description' => $input['description'] ?? null,
                'privileges' => $input['privileges'] ?? [],
            ],
            partial: false,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->feature;
    }
}
