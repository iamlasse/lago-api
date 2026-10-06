<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\CatalogPlan;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PlanRateCards\CreateService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PlanAppliedRateCards::Create
 * (app/graphql/mutations/plan_applied_rate_cards/create.rb): "Applies a rate
 * card to a plan" — product_catalog-gated; the public argument stays plan_id
 * while the plans internally live in the catalog_plans table.
 */
class CreatePlanAppliedRateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.catalog_plans.find_by(id: args[:plan_id]).
        $catalogPlan = CatalogPlan::query()
            ->where('organization_id', $organization->id)
            ->find($input['plan_id'] ?? null);

        // Rails: params: args.except(:plan_id).
        unset($input['plan_id']);

        $result = CreateService::call(catalogPlan: $catalogPlan, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->plan_rate_card;
    }
}
