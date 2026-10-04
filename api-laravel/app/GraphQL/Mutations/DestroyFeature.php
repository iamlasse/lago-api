<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Feature;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Features\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Entitlement::DestroyFeature
 * (app/graphql/mutations/entitlement/destroy_feature.rb): "Destroys an
 * existing feature".
 */
class DestroyFeature
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.features.find_by(id: args[:id]).
        $feature = Feature::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(feature: $feature);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->feature;
    }
}
