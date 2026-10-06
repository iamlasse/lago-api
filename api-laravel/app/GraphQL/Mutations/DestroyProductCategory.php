<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\ProductCategory;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Services\ProductCategories\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ProductCategories::Destroy
 * (app/graphql/mutations/product_categories/destroy.rb): "Deletes a
 * product_category" — product_catalog-gated; the payload's `id` is the
 * destroyed category's id.
 */
class DestroyProductCategory
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.product_categories.find_by(id:).
        $productCategory = ProductCategory::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(productCategory: $productCategory);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->product_category;
    }
}
