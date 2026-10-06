<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Plan;
use App\Jobs\SendWebhookJob;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\Services\Utils\Entitlement;
use App\GraphQL\Support\LagoContext;
use App\Services\Plans\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Entitlements\PlanEntitlementsUpdateService;

/**
 * Port of Rails' Mutations::Plans::Update (app/graphql/mutations/plans/update.rb):
 * "Updates an existing Plan".
 *
 * NOTE: when entitlements are provided, the plan.updated webhook is emitted
 * here (after the entitlements are persisted) so its payload includes them;
 * otherwise UpdateService emits it.
 */
class UpdatePlan
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $entitlements = $input['entitlements'] ?? null;
        unset($input['entitlements']);

        // Rails: current_organization.plans.find_by(id: args[:id]).
        $plan = Plan::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(
            plan: $plan,
            params: $input,
            sendWebhook: $entitlements === null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        if ($entitlements !== null) {
            $result = PlanEntitlementsUpdateService::call(
                organization: $plan->organization,
                plan: $plan,
                entitlementsParams: Entitlement::convertGqlInputToParams($entitlements),
                partial: false,
                sendWebhook: false,
            );

            if ($result->success()) {
                // Rails: SendWebhookJob.perform_after_commit("plan.updated", plan).
                SendWebhookJob::performLater('plan.updated', $plan);
            }
        }

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $plan->refresh();
    }
}
