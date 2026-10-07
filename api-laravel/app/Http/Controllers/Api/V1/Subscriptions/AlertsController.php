<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Subscriptions;

use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\UsageMonitoring\Alert;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Queries\UsageMonitoring\AlertsQuery;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\UsageMonitoring\CreateAlertService;
use App\Services\UsageMonitoring\UpdateAlertService;
use App\Services\UsageMonitoring\DestroyAlertService;
use App\Serializers\V1\UsageMonitoring\AlertSerializer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Services\UsageMonitoring\Alerts\DestroyAllService;
use App\Services\UsageMonitoring\Alerts\CreateBatchService;

/**
 * Port of Rails' Api::V1::Subscriptions::AlertsController
 * (app/controllers/api/v1/subscriptions/alerts_controller.rb) — the
 * subscription-scoped alert CRUD under /subscriptions/:external_id/alerts.
 */
class AlertsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'alert';

    public function index(Request $request): JsonResponse
    {
        $result = AlertsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: ['subscription_external_id' => $this->findSubscription($request)->external_id],
        );

        if (! $result->success()) {
            $this->renderErrorResponse($result);
        }

        /** @var LengthAwarePaginator $alerts */
        $alerts = $result->alerts;

        return $this->renderSerializerJson((new CollectionSerializer(
            $alerts,
            AlertSerializer::class,
            [
                'collection_name' => 'alerts',
                'meta' => $this->paginationMetadata($alerts),
                'includes' => ['thresholds'],
            ],
        ))->toJson());
    }

    public function show(Request $request): JsonResponse
    {
        return $this->renderAlert($this->findAlert($request));
    }

    public function create(Request $request): JsonResponse
    {
        if ($request->input('alerts') !== null) {
            return $this->createBatch($request);
        }

        return $this->renderAlertAction(
            CreateAlertService::call(
                organization: $this->currentOrganization(),
                alertable: $this->findSubscription($request),
                params: $this->createParams($request),
            ),
        );
    }

    public function update(Request $request): JsonResponse
    {
        return $this->renderAlertAction(
            UpdateAlertService::call(
                alert: $this->findAlert($request),
                params: $this->updateParams($request),
            ),
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->renderAlertAction(
            DestroyAlertService::call(alert: $this->findAlert($request)),
        );
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $result = DestroyAllService::call(alertable: $this->findSubscription($request));

        if ($result->success()) {
            return response()->json(null, 200);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers -----------------------------------------------------------------

    private function createBatch(Request $request): JsonResponse
    {
        $result = CreateBatchService::call(
            organization: $this->currentOrganization(),
            alertable: $this->findSubscription($request),
            alertsParams: (array) $request->input('alerts'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new CollectionSerializer(
                $result->alerts,
                AlertSerializer::class,
                ['collection_name' => 'alerts', 'includes' => ['thresholds']],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    private function findSubscription(Request $request): Subscription
    {
        $externalId = $request->route('external_id');

        $subscription = $this->currentOrganization()->subscriptions()
            ->where('external_id', is_scalar($externalId) ? (string) $externalId : '')
            ->latest('started_at')
            ->first();

        if ($subscription === null) {
            throw new NotFoundException('subscription');
        }

        return $subscription;
    }

    private function findAlert(Request $request): Alert
    {
        $subscription = $this->findSubscription($request);

        $alert = $this->currentOrganization()->alerts()
            ->where('subscription_external_id', $subscription->external_id)
            ->where('code', $request->route('alert_code') ?? $request->route('code'))
            ->first();

        if ($alert === null) {
            throw new NotFoundException('alert');
        }

        return $alert;
    }

    private function renderAlertAction(BaseResult $result): JsonResponse
    {
        if ($result->success()) {
            return $this->renderAlert($result->alert);
        }

        $this->renderErrorResponse($result);
    }

    private function renderAlert(Alert $alert): JsonResponse
    {
        return $this->renderSerializerJson((new AlertSerializer(
            $alert,
            ['root_name' => 'alert', 'includes' => ['thresholds']],
        ))->toJson());
    }

    /**
     * Port of `params.require(:alert).permit(:alert_type, :code, :name,
     * :billable_metric_code, thresholds: [...])`.
     *
     * @return array<string, mixed>
     */
    private function createParams(Request $request): array
    {
        $alert = (array) $request->input('alert', []);

        return [
            'alert_type' => $alert['alert_type'] ?? null,
            'code' => $alert['code'] ?? null,
            'name' => $alert['name'] ?? null,
            'billable_metric_code' => $alert['billable_metric_code'] ?? null,
            'thresholds' => isset($alert['thresholds']) ? (array) $alert['thresholds'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function updateParams(Request $request): array
    {
        $alert = (array) $request->input('alert', []);
        $params = [];

        foreach (['code', 'name', 'billable_metric_code'] as $key) {
            if (array_key_exists($key, $alert)) {
                $params[$key] = $alert[$key];
            }
        }

        if (array_key_exists('thresholds', $alert)) {
            $params['thresholds'] = $alert['thresholds'] === null ? null : (array) $alert['thresholds'];
        }

        return $params;
    }
}
