<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Features\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Entitlement::CreateFeature
 * (app/graphql/mutations/entitlement/create_feature.rb): "Creates a new
 * feature".
 */
class CreateFeature
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(
            organization: $organization,
            params: [
                'code' => $input['code'] ?? null,
                'name' => $input['name'] ?? null,
                'description' => $input['description'] ?? null,
                'privileges' => $input['privileges'] ?? [],
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->feature;
    }
}
