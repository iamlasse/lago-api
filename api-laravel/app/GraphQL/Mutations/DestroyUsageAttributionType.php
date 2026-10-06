<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresAccountTree;
use App\Services\UsageAttributionTypes\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::UsageAttributionTypes::Destroy
 * (app/graphql/mutations/usage_attribution_types/destroy.rb): "Deletes a
 * usage attribution type" — discards the type and its attributed values,
 * gated by the account_tree feature flag; the payload carries the id.
 */
class DestroyUsageAttributionType
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresAccountTree::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $usageAttributionType = $organization->usageAttributionTypes()
            ->where('id', $args['input']['id'] ?? null)
            ->first();

        $result = DestroyService::call($usageAttributionType);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['id' => $result->usage_attribution_type->id];
    }
}
