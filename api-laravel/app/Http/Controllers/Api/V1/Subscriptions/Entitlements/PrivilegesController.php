<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subscriptions\Entitlements;

use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Enums\SubscriptionStatus;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\Base\CollectionSerializer;
use App\Models\Entitlement\SubscriptionEntitlement;
use App\Serializers\V1\Entitlement\SubscriptionEntitlementSerializer;
use App\Services\Entitlements\SubscriptionFeaturePrivilegeRemoveService;

/**
 * Port of Rails' Api::V1::Subscriptions::Entitlements::PrivilegesController
 * (app/controllers/api/v1/subscriptions/entitlements/privileges_controller.rb)
 * — the nested DELETE /subscriptions/:subscription_external_id/entitlements/
 * :entitlement_code/privileges/:code.
 */
class PrivilegesController extends ApiController
{
    public function destroy(Request $request): JsonResponse
    {
        $subscription = $this->findSubscription($request);

        $result = SubscriptionFeaturePrivilegeRemoveService::call(
            subscription: $subscription,
            featureCode: (string) $request->route('entitlement_code'),
            privilegeCode: (string) $request->route('code'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                SubscriptionEntitlement::forSubscription($subscription),
                SubscriptionEntitlementSerializer::class,
                ['collection_name' => 'entitlements'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers -----------------------------------------------------------------------

    /** Rails: `find_subscription` — same lookup as the parent controller. */
    private function findSubscription(Request $request): Subscription
    {
        $statusParam = $request->query('subscription_status') ?? $request->query('status');

        $status = $statusParam !== null
            ? SubscriptionStatus::fromOption($statusParam)
            : SubscriptionStatus::Active->value;

        $subscription = Subscription::query()
            ->where('organization_id', (string) $this->currentOrganization()->id)
            ->where('external_id', (string) $request->route('external_id'))
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByRaw('terminated_at DESC NULLS FIRST')
            ->orderByDesc('started_at')
            ->first();

        if ($subscription === null) {
            throw new NotFoundException('subscription');
        }

        return $subscription;
    }
}
