<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Order;
use App\Queries\OrdersQuery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Orders\UpdateService;
use App\Serializers\V1\OrderSerializer;
use App\Services\Orders\ExecuteService;
use App\Exceptions\Api\NotFoundException;
use App\Exceptions\Api\ForbiddenException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;

/**
 * Port of Rails' Api::V1::OrdersController
 * (app/controllers/api/v1/orders_controller.rb) — orders are keyed by uuid
 * id. Every action gates on the order_forms feature flag (Rails:
 * ensure_feature_flag! → 403 feature_unavailable).
 *
 * There is no REST order update endpoint: restating execution_mode on
 * POST /execute is the only way to set or change it (Rails'
 * update_execution_mode), which is why execute both updates and runs.
 */
class OrdersController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'order';

    public function index(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $result = OrdersQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
            filters: $this->indexFilters($request),
            searchTerm: $request->query('search_term'),
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->orders,
                    OrderSerializer::class,
                    [
                        'collection_name' => 'orders',
                        'meta' => $this->paginationMetadata($result->orders),
                        'includes' => ['billing_snapshot'],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $order = $this->currentOrganization()
            ->orders()
            ->where('id', $request->route('id'))
            ->first();

        if ($order === null) {
            throw new NotFoundException('order');
        }

        return $this->renderOrder($order);
    }

    public function execute(Request $request): JsonResponse
    {
        $this->ensureOrderFormsFeature();

        $order = $this->currentOrganization()
            ->orders()
            ->where('id', $request->route('id'))
            ->first();

        if ($order === null) {
            throw new NotFoundException('order');
        }

        $updateResult = $this->updateExecutionMode($order, $request);

        if ($updateResult !== null && $updateResult->failure()) {
            $this->renderErrorResponse($updateResult);
        }

        try {
            $result = ExecuteService::call(order: $order);
        } catch (\App\Services\Failures\LockAcquisitionFailure $e) {
            // Rails: rescue BaseLockService::FailedToAcquireLock.
            return $this->lockAcquisitionError();
        } catch (\App\Models\Exceptions\SequenceException $e) {
            // The sequenced number assignment raises the advisory
            // lock_timeout failure (SQLSTATE 55P03) — same envelope.
            return $this->lockAcquisitionError();
        }

        if ($result->success()) {
            return $this->renderOrder($result->order);
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Rails: ensure_feature_flag! — forbidden_error(code:
     * "feature_unavailable") unless the order_forms flag is on.
     */
    protected function ensureOrderFormsFeature(): void
    {
        $organization = $this->currentOrganization();

        if ($organization === null
            || ! in_array('order_forms', (array) ($organization->feature_flags ?? []), true)) {
            throw new ForbiddenException('feature_unavailable');
        }
    }

    /**
     * Rails: `index_filters` — the orders index query params, verbatim.
     *
     * @return array<string, mixed>
     */
    protected function indexFilters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'order_type' => $request->query('order_type'),
            'execution_mode' => $request->query('execution_mode'),
            'customer_id' => $request->query('customer_id'),
            'number' => $request->query('number'),
            'order_form_number' => $request->query('order_form_number'),
            'quote_number' => $request->query('quote_number'),
            'owner_id' => $request->query('owner_id'),
            'executed_at_from' => $request->query('executed_at_from'),
            'executed_at_to' => $request->query('executed_at_to'),
        ];
    }

    /**
     * Rails: `update_execution_mode` — execution_mode is normally chosen
     * when the order form is signed. REST has no order update endpoint, so
     * restating it here is the only way to set or change it. Skipping the
     * update when the value is unchanged keeps a retry of a failed order
     * from tripping not_editable.
     */
    protected function updateExecutionMode(Order $order, Request $request): ?object
    {
        /** @var mixed $orderParams */
        $orderParams = $request->input('order');
        $executionMode = is_array($orderParams) ? ($orderParams['execution_mode'] ?? null) : null;

        if (! is_string($executionMode) || $executionMode === ''
            || $order->execution_mode?->value === $executionMode) {
            return null;
        }

        return UpdateService::call(order: $order, params: ['execution_mode' => $executionMode]);
    }

    /**
     * Rails: rescue BaseLockService::FailedToAcquireLock →
     * lock_acquisition_error(code: "lock_acquisition_failed") — a bare-code
     * 422 envelope (no error_details).
     */
    protected function lockAcquisitionError(): JsonResponse
    {
        return JsonResponse::fromJsonString((string) json_encode([
            'status' => 422,
            'error' => 'Unprocessable Entity',
            'code' => 'lock_acquisition_failed',
        ]));
    }

    private function renderOrder(Order $order): JsonResponse
    {
        return $this->renderSerializerJson(
            (new OrderSerializer(
                $order,
                ['root_name' => 'order', 'includes' => ['billing_snapshot']],
            ))->toJson()
        );
    }
}
