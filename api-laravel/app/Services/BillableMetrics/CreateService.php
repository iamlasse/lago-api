<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' BillableMetrics::CreateService
 * (app/services/billable_metrics/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): filters args (BillableMetricFilters::CreateOrUpdateBatchService).
 * - TODO(port): SendWebhookJob.perform_after_commit("billable_metric.created")
 *   — webhooks are a later milestone; the emission point is marked below.
 * - TODO(port): SegmentTrackJob "billable_metric_created".
 * - TODO(port): activity log middleware (activity_loggable).
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billable_metric');
        $args = $this->args;

        $organization = isset($args['organization_id'])
            ? Organization::query()->find($args['organization_id'])
            : null;

        if (($args['aggregation_type'] ?? null) === 'custom_agg' && ! ($organization?->custom_aggregation)) {
            return $result->forbiddenFailure();
        }

        $metric = new BillableMetric([
            'organization_id' => $organization?->id,
            'name' => $args['name'] ?? null,
            'code' => $args['code'] ?? null,
            'description' => $args['description'] ?? null,
            'recurring' => $args['recurring'] ?? false,
            'aggregation_type' => $args['aggregation_type'] ?? null,
            'field_name' => $args['field_name'] ?? null,
            'rounding_function' => $args['rounding_function'] ?? null,
            'rounding_precision' => $args['rounding_precision'] ?? null,
            'weighted_interval' => $args['weighted_interval'] ?? null,
            'expression' => $args['expression'] ?? null,
        ]);

        try {
            DB::transaction(function () use ($metric, $result): void {
                $errors = $metric->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $metric->save();

                // TODO(port): filters args — BillableMetricFilters::
                // CreateOrUpdateBatchService(billable_metric:, filters_params:)
                // followed by .raise_if_error!.
            });

            ExpressionCacheService::expireCache(
                (string) $metric->organization_id,
                (string) $metric->code,
            ); // an event received for this code before the metric existed cached the absence of an expression.

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "billable_metric.created", metric) — webhook emission hook point.
            // TODO(port): SegmentTrackJob "billable_metric_created".

            $result->billable_metric = $metric;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `track_billable_metric_created` — SegmentTrackJob with the
     * metric properties (membership_id: CurrentContext.membership).
     *
     * TODO(port): Segment tracking — SegmentTrackJob.perform_later(
     *   membership_id:, event: "billable_metric_created", properties: {
     *   code:, name:, description:, aggregation_type:, aggregation_property:
     *   (field_name), organization_id: }).
     */
}
