<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Plan;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Plans\PrepareDestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Plans::Destroy (app/graphql/mutations/plans/destroy.rb):
 * "Deletes a Plan" — the payload's `id` is the destroyed plan's id.
 */
class DestroyPlan
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.plans.find_by(id:).
        $plan = Plan::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = PrepareDestroyService::call(plan: $plan);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->plan;
    }
}
