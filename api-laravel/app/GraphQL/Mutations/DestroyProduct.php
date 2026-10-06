<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Product;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Products\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Products::Destroy (app/graphql/mutations/products/destroy.rb):
 * "Deletes a product" — product_catalog-gated; the payload's `id` is the
 * destroyed product's id.
 */
class DestroyProduct
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.products.find_by(id:).
        $product = Product::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(product: $product);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->product;
    }
}
