<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Subscription;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Entitlements\SubscriptionFeatureRemoveService;

/**
 * Port of Rails' Mutations::Entitlement::RemoveSubscriptionEntitlement
 * (app/graphql/mutations/entitlement/remove_subscription_entitlement.rb):
 * "Removes a feature entitlement from a subscription" — resolves to the
 * RemoveSubscriptionEntitlementPayload shape { featureCode }.
 */
class RemoveSubscriptionEntitlement
{
    /**
     * @return array<string, string>
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->find($input['subscription_id'] ?? null);

        $result = SubscriptionFeatureRemoveService::call(
            subscription: $subscription,
            featureCode: (string) ($input['feature_code'] ?? ''),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return ['feature_code' => (string) $result->featureCode];
    }
}
