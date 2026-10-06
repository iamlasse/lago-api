<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\ProductFilter;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Services\ProductFilters\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ProductFilters::Destroy
 * (app/graphql/mutations/product_filters/destroy.rb): "Deletes a product
 * filter" — product_catalog-gated; the payload's `id` is the destroyed
 * filter's id.
 */
class DestroyProductFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.product_filters.find_by(id:).
        $productFilter = ProductFilter::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(productFilter: $productFilter);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->product_filter;
    }
}
