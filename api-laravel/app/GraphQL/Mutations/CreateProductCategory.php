<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Services\ProductCategories\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ProductCategories::Create
 * (app/graphql/mutations/product_categories/create.rb): "Creates a new
 * product_category" — product_catalog-gated.
 */
class CreateProductCategory
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(organization: $organization, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->product_category;
    }
}
