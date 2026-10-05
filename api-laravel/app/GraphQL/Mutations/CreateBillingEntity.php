<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\BillingEntities\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::BillingEntities::Create
 * (app/graphql/mutations/billing_entities/create.rb): "Creates a new Billing
 * Entity" — the input goes to BillingEntities::CreateService with the
 * current organization.
 */
class CreateBillingEntity
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = CreateService::call(
            organization: $organization,
            params: Args::snakeKeys(Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->billing_entity;
    }
}
