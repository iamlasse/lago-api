<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Contracts\TerminateService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Contracts::Terminate (app/graphql/mutations/contracts/terminate.rb):
 * "Terminates a live contract" — product_catalog-gated; the terminatable
 * (active) contract is addressed by external_id.
 */
class TerminateContract
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.contracts.terminatable_by_external_id(external_id).
        $contract = Contract::terminatableByExternalId(
            $input['external_id'] ?? '',
            $organization->id,
        );

        $result = TerminateService::call(contract: $contract);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->contract;
    }
}
