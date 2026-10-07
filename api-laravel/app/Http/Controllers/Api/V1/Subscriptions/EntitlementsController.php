<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subscriptions;

use App\Models\Subscription;
use Illuminate\Http\Request;
use App\Enums\SubscriptionStatus;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Serializers\Base\CollectionSerializer;
use App\Models\Entitlement\SubscriptionEntitlement;
use App\Services\Entitlements\SubscriptionFeatureRemoveService;
use App\Services\Entitlements\SubscriptionEntitlementsUpdateService;
use App\Serializers\V1\Entitlement\SubscriptionEntitlementSerializer;

/**
 * Port of Rails' Api::V1::Subscriptions::EntitlementsController
 * (app/controllers/api/v1/subscriptions/entitlements_controller.rb) — the
 * nested subscription entitlements subresource. There is no create: the
 * PATCH merges the entitlements hash into the subscription (partial), and
 * DELETE removes a single feature entitlement.
 */
class EntitlementsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $subscription = $this->findSubscription($request);

        return $this->renderEntitlements(
            SubscriptionEntitlement::forSubscription($subscription),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $subscription = $this->findSubscription($request);

        $result = SubscriptionEntitlementsUpdateService::call(
            subscription: $subscription,
            entitlementsParams: $this->updateParams($request),
            partial: true,
        );

        if ($result->success()) {
            return $this->renderEntitlements(
                SubscriptionEntitlement::forSubscription($subscription),
            );
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $subscription = $this->findSubscription($request);

        $result = SubscriptionFeatureRemoveService::call(
            subscription: $subscription,
            featureCode: (string) $request->route('code'),
        );

        if ($result->success()) {
            return $this->renderEntitlements(
                SubscriptionEntitlement::forSubscription($subscription),
            );
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers -----------------------------------------------------------------------

    /**
     * Rails: `find_subscription` — the organization's subscription by
     * external_id, filtered on `subscription_status || status || :active`
     * (both params kept for backward compatibility), newest first.
     */
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
            ->latest('started_at')
            ->first();

        if ($subscription === null) {
            throw new NotFoundException('subscription');
        }

        return $subscription;
    }

    /**
     * @param  iterable<SubscriptionEntitlement>  $entitlements
     */
    private function renderEntitlements(iterable $entitlements): JsonResponse
    {
        return $this->renderSerializerJson((new CollectionSerializer(
            $entitlements,
            SubscriptionEntitlementSerializer::class,
            ['collection_name' => 'entitlements'],
        ))->toJson());
    }

    /**
     * Rails: `params.fetch(:entitlements, {}).permit!` — the hash of
     * feature code => {privilege code => value} is taken verbatim.
     *
     * @return array<string, mixed>
     */
    private function updateParams(Request $request): array
    {
        $entitlements = $request->input('entitlements');

        return is_array($entitlements) ? $entitlements : [];
    }
}
