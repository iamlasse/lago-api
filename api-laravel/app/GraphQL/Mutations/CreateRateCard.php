<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Product;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\RateCards\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RateCards::Create (app/graphql/mutations/rate_cards/create.rb):
 * "Creates a new rate card" — product_catalog-gated.
 */
class CreateRateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.products.find_by(id: args[:product_id]).
        $product = Product::query()
            ->where('organization_id', $organization->id)
            ->find($input['product_id'] ?? null);

        // Rails: params: args.except(:product_id).
        unset($input['product_id']);

        $result = CreateService::call(product: $product, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_card;
    }
}
