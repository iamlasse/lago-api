<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\ProductFilter;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\ProductFilters\UpdateService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ProductFilters::Update
 * (app/graphql/mutations/product_filters/update.rb): "Updates an existing
 * product filter" — product_catalog-gated.
 */
class UpdateProductFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.product_filters.find_by(id: args[:id]).
        $productFilter = ProductFilter::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        // Rails: params: args.except(:id).
        unset($input['id']);

        $result = UpdateService::call(productFilter: $productFilter, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->product_filter;
    }
}
