<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\RateCard;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\RateCardRates\CreateService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RateCardRates::Create
 * (app/graphql/mutations/rate_card_rates/create.rb): "Adds a rate to a rate
 * card" — product_catalog-gated.
 */
class CreateRateCardRate
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.rate_cards.find_by(id: args[:rate_card_id]).
        $rateCard = RateCard::query()
            ->where('organization_id', $organization->id)
            ->find($input['rate_card_id'] ?? null);

        // Rails: params: args.except(:rate_card_id).
        unset($input['rate_card_id']);

        $result = CreateService::call(rateCard: $rateCard, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_card_rate;
    }
}
