<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\BillingEntities\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::BillingEntities::Update
 * (app/graphql/mutations/billing_entities/update.rb): finds the active
 * billing entity by id and hands it to BillingEntities::UpdateService with
 * the whole input.
 */
class UpdateBillingEntity
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $params = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.billing_entities.find_by(id:) — the
        // active scope; an unknown id passes null and UpdateService answers
        // the not_found envelope.
        $billingEntity = $organization->billingEntities()
            ->where('id', $params['id'] ?? null)
            ->first();

        $result = UpdateService::call(
            billingEntity: $billingEntity,
            params: $params,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->billing_entity;
    }
}
