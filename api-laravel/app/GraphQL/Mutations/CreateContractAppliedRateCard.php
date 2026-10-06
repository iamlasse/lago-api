<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Contract;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Services\ContractRateCards\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ContractAppliedRateCards::Create
 * (app/graphql/mutations/contract_applied_rate_cards/create.rb): "Attaches a
 * rate card to a pending contract" — product_catalog-gated; the editable
 * (pending) contract is the one a card can be attached to.
 */
class CreateContractAppliedRateCard
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

        $result = CreateService::call(contract: $contract, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->contract_rate_card;
    }
}
