<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Str;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Contracts\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Contracts::Create (app/graphql/mutations/contracts/create.rb):
 * "Creates a new contract" — product_catalog-gated; a blank external_id
 * defaults to a generated uuid.
 */
class CreateContract
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: params.merge(external_id: args[:external_id].presence ||
        // SecureRandom.uuid).
        $input['external_id'] = ($input['external_id'] ?? null) ?: Str::uuid()->toString();

        $result = CreateService::call(organization: $organization, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->contract;
    }
}
