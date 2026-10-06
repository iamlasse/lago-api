<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\RateCardRate;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\RateCardRates\DestroyService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RateCardRates::Destroy
 * (app/graphql/mutations/rate_card_rates/destroy.rb): "Deletes a pending rate
 * of a rate card" — product_catalog-gated; the payload's `id` is the
 * destroyed rate's id.
 */
class DestroyRateCardRate
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.rate_card_rates.find_by(id:).
        $rateCardRate = RateCardRate::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(rateCardRate: $rateCardRate);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_card_rate;
    }
}
