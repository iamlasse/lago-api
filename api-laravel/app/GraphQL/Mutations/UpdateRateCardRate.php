<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\RateCardRate;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\RateCardRates\UpdateService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RateCardRates::Update
 * (app/graphql/mutations/rate_card_rates/update.rb): "Updates a rate of a
 * rate card" — product_catalog-gated.
 */
class UpdateRateCardRate
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.rate_card_rates.find_by(id: args[:id]).
        $rateCardRate = RateCardRate::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        // Rails: params: args.except(:id).
        unset($input['id']);

        $result = UpdateService::call(rateCardRate: $rateCardRate, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_card_rate;
    }
}
