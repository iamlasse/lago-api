<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Queries\BillableMetricsQuery;
use App\Exceptions\Api\NotFoundException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\Pagination;
use App\Serializers\Base\CollectionSerializer;
use App\Services\BillableMetrics\CreateService;
use App\Services\BillableMetrics\UpdateService;
use App\Serializers\V1\BillableMetricSerializer;
use App\Services\BillableMetrics\DestroyService;
use App\Exceptions\Api\ParameterMissingException;
use App\Services\BillableMetrics\EvaluateExpressionService;
use App\Serializers\V1\BillableMetricExpressionResultSerializer;

/**
 * Port of Rails' Api::V1::BillableMetricsController
 * (app/controllers/api/v1/billable_metrics_controller.rb).
 *
 * The filters param is wired to BillableMetricFilters::
 * CreateOrUpdateBatchService (usage-monitoring slice).
 *
 * evaluate_expression runs on the App\Expression parser port of the
 * lago-expression gem (see EvaluateExpressionService).
 */
class BillableMetricsController extends ApiController
{
    use Pagination;

    protected ?string $resourceName = 'billable_metric';

    public function create(Request $request): JsonResponse
    {
        $result = CreateService::call(args: array_merge($this->inputParams($request), [
            'organization_id' => $this->currentOrganization()->id,
        ]));

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSerializerJson((new BillableMetricSerializer(
                $result->billable_metric,
                ['root_name' => 'billable_metric', 'includes' => ['counters']],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function update(Request $request): JsonResponse
    {
        $billableMetric = $this->currentOrganization()
            ->billableMetrics()
            ->where('code', $request->route('code'))
            ->first();

        $result = UpdateService::call(
            billableMetric: $billableMetric,
            params: $this->inputParams($request),
        );

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSerializerJson((new BillableMetricSerializer(
                $result->billable_metric,
                ['root_name' => 'billable_metric', 'includes' => ['counters']],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function destroy(Request $request): JsonResponse
    {
        $metric = $this->currentOrganization()
            ->billableMetrics()
            ->where('code', $request->route('code'))
            ->first();

        $result = DestroyService::call(metric: $metric);

        if ($result->success()) {
            // TODO(port): api_logs + audit (ApiLoggable/Trackable — non-GET
            // writes append an api log and an audit log).

            return $this->renderSerializerJson((new BillableMetricSerializer(
                $result->billable_metric,
                ['root_name' => 'billable_metric', 'includes' => ['counters']],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        $metric = $this->currentOrganization()
            ->billableMetrics()
            ->where('code', $request->route('code'))
            ->first();

        if ($metric === null) {
            throw new NotFoundException('billable_metric');
        }

        return $this->renderSerializerJson((new BillableMetricSerializer(
            $metric,
            ['root_name' => 'billable_metric'],
        ))->toJson());
    }

    public function index(Request $request): JsonResponse
    {
        $result = BillableMetricsQuery::call(
            organization: $this->currentOrganization(),
            pagination: [
                'page' => $request->query('page'),
                'limit' => $request->query('per_page') ?: self::PER_PAGE,
            ],
        );

        if ($result->success()) {
            return $this->renderSerializerJson(
                (new CollectionSerializer(
                    $result->billable_metrics,
                    BillableMetricSerializer::class,
                    [
                        'collection_name' => 'billable_metrics',
                        'meta' => $this->paginationMetadata($result->billable_metrics),
                        // DEPRECATED since 2024-11-22 (Rails includes %i[counters]).
                        'includes' => ['counters'],
                    ],
                ))->toJson()
            );
        }

        $this->renderErrorResponse($result);
    }

    public function evaluateExpression(Request $request): JsonResponse
    {
        $result = EvaluateExpressionService::call(
            expression: $this->scalarParam($request, 'expression'),
            event: $this->expressionEventParams($request),
        );

        if ($result->success()) {
            return $this->renderSerializerJson((new BillableMetricExpressionResultSerializer(
                $result,
                ['root_name' => 'expression_result'],
            ))->toJson());
        }

        $this->renderErrorResponse($result);
    }

    // -- Helpers ---------------------------------------------------------------------

    /**
     * Port of `params.expect(billable_metric: [...])` — the create/update
     * contract, verbatim (Rails' permitted params; filters are permitted
     * shape-wise until the BillableMetricFilters slice lands).
     *
     * @return array<string, mixed>
     */
    private function inputParams(Request $request): array
    {
        /** @var mixed $billableMetric */
        $billableMetric = $this->requireParam($request, 'billable_metric');

        if (! is_array($billableMetric)) {
            throw new ParameterMissingException('billable_metric');
        }

        return $this->permitParams($billableMetric, [
            'name',
            'code',
            'description',
            'aggregation_type',
            'weighted_interval',
            'recurring',
            'field_name',
            'expression',
            'rounding_function',
            'rounding_precision',
            'filters' => [['key', 'values' => []]],
        ]);
    }

    /** Port of `expression_event_params` — `params.permit(event: [...])`. */
    private function expressionEventParams(Request $request): ?array
    {
        /** @var mixed $event */
        $event = $request->input('event');

        if (! is_array($event)) {
            return null;
        }

        return $this->permitParams($event, [
            'code',
            'timestamp',
            'properties' => '*',
        ]);
    }

    /**
     * Port of the top-level `params[:expression]` read — only a scalar (or
     * absent) value survives.
     */
    private function scalarParam(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
