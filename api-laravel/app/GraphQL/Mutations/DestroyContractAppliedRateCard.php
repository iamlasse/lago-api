<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\ContractRateCard;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Services\ContractRateCards\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ContractAppliedRateCards::Destroy
 * (app/graphql/mutations/contract_applied_rate_cards/destroy.rb): "Removes a
 * rate card from a pending contract" — product_catalog-gated.
 */
class DestroyContractAppliedRateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: ContractRateCard.where(organization:).find_by(id:).
        $contractRateCard = ContractRateCard::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(contractRateCard: $contractRateCard);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->contract_rate_card;
    }
}
