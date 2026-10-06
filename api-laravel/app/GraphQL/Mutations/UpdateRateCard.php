<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\RateCard;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\RateCards\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RateCards::Update (app/graphql/mutations/rate_cards/update.rb):
 * "Updates an existing rate card" — product_catalog-gated.
 */
class UpdateRateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.rate_cards.find_by(id: args[:id]).
        $rateCard = RateCard::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        // Rails: params: args.except(:id).
        unset($input['id']);

        $result = UpdateService::call(rateCard: $rateCard, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_card;
    }
}
