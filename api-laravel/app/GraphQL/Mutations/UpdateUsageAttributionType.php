<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\UsageAttributionType;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresAccountTree;
use App\Services\UsageAttributionTypes\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::UsageAttributionTypes::Update
 * (app/graphql/mutations/usage_attribution_types/update.rb): "Updates an
 * existing usage attribution type" — found among the organization's kept
 * types (Rails: find_by — an unknown id null-stubs the payload, mirroring
 * the resolver convention), gated by the account_tree feature flag.
 */
class UpdateUsageAttributionType
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?UsageAttributionType
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresAccountTree::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $usageAttributionType = $organization->usageAttributionTypes()
            ->where('id', $args['input']['id'] ?? null)
            ->first();

        $result = UpdateService::call(
            usageAttributionType: $usageAttributionType,
            params: array_diff_key(Args::snakeKeys(Args::input($args)), ['id' => true]),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->usage_attribution_type;
    }
}
