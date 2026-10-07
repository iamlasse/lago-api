<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subscriptions;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Services\LifetimeUsages\UpdateService;
use App\Serializers\V1\LifetimeUsageSerializer;

/**
 * Port of Rails' Api::V1::Subscriptions::LifetimeUsagesController
 * (app/controllers/api/v1/subscriptions/lifetime_usages_controller.rb).
 */
class LifetimeUsagesController extends ApiController
{
    protected ?string $resourceName = 'lifetime_usage';

    public function show(Request $request): JsonResponse
    {
        // Note (Rails): lifetime usage is counted against all sub upgrades-downgrades.
        $lifetimeUsage = $this->findSubscription($request)?->lifetimeUsage;

        if ($lifetimeUsage === null) {
            throw new NotFoundException('lifetime_usage');
        }

        return $this->renderLifetimeUsage($lifetimeUsage);
    }

    public function update(Request $request): JsonResponse
    {
        $lifetimeUsage = $this->findSubscription($request)?->lifetimeUsage;

        $result = UpdateService::call(
            lifetimeUsage: $lifetimeUsage,
            params: [
                'external_historical_usage_amount_cents' => $request->input('lifetime_usage.external_historical_usage_amount_cents'),
            ],
        );

        if (! $result->success()) {
            $this->renderErrorResponse($result);
        }

        return $this->renderLifetimeUsage($result->lifetime_usage);
    }

    // -- Helpers -----------------------------------------------------------------

    private function findSubscription(Request $request): ?\App\Models\Subscription
    {
        $externalId = $request->route('external_id');

        return $this->currentOrganization()->subscriptions()
            ->where('external_id', is_scalar($externalId) ? (string) $externalId : '')
            ->latest('started_at')
            ->first();
    }

    private function renderLifetimeUsage(\App\Models\LifetimeUsage $lifetimeUsage): JsonResponse
    {
        return $this->renderSerializerJson((new LifetimeUsageSerializer(
            $lifetimeUsage,
            ['root_name' => 'lifetime_usage', 'includes' => ['usage_thresholds']],
        ))->toJson());
    }
}
