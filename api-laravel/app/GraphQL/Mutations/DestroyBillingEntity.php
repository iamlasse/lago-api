<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\BillingEntity;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::BillingEntities::Destroy
 * (app/graphql/mutations/billing_entities/destroy.rb) — UPSTREAM IS A STUB:
 * it takes a code argument but simply returns
 * `current_organization.default_billing_entity` and destroys nothing. Ported
 * verbatim so the two runtimes agree; revisit if upstream implements it.
 */
class DestroyBillingEntity
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?BillingEntity
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        return $organization->defaultBillingEntity;
    }
}
