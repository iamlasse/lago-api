<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Contracts\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Contracts::Update (app/graphql/mutations/contracts/update.rb):
 * "Updates a contract; once active, only its administrative settings" —
 * product_catalog-gated; the editable (live) contract is addressed by
 * external_id.
 */
class UpdateContract
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $externalId = $input['external_id'] ?? '';
        unset($input['external_id']);

        // Rails: current_organization.contracts.live_by_external_id(external_id).
        $contract = Contract::liveByExternalId($externalId, $organization->id);

        $result = UpdateService::call(contract: $contract, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->contract;
    }
}
