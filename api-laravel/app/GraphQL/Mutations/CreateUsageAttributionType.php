<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresAccountTree;
use App\Services\UsageAttributionTypes\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::UsageAttributionTypes::Create
 * (app/graphql/mutations/usage_attribution_types/create.rb): "Creates a new
 * usage attribution type" — the input goes to
 * UsageAttributionTypes::CreateService with the current organization,
 * gated by the account_tree feature flag.
 */
class CreateUsageAttributionType
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresAccountTree::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = CreateService::call(
            organization: $organization,
            params: Args::snakeKeys(Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->usage_attribution_type;
    }
}
