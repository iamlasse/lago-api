<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_key_exists;

/**
 * Port of Rails' BillableMetrics::UpdateService
 * (app/services/billable_metrics/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): SendWebhookJob.perform_after_commit("billable_metric.updated").
 * - TODO(port): activity log middleware (activity_loggable).
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?BillableMetric $billableMetric,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('billable_metric');
        $metric = $this->billableMetric;
        $params = $this->params;

        if ($metric === null) {
            return $result->notFoundFailure('billable_metric');
        }

        if (array_key_exists('aggregation_type', $params) &&
            ($params['aggregation_type'] ?? null) === 'custom_agg' &&
            ! ($metric->organization?->custom_aggregation)) {
            return $result->forbiddenFailure();
        }

        try {
            // Rails: billable_metric.with_lock { ... } — SELECT … FOR UPDATE
            // inside a transaction.
            $metric = DB::transaction(function () use ($metric, $params, $result): BillableMetric {
                $locked = BillableMetric::query()->lockForUpdate()->find($metric->id);

                if (array_key_exists('name', $params)) {
                    $locked->name = $params['name'];
                }
                if (array_key_exists('description', $params)) {
                    $locked->description = $params['description'];
                }

                // BillableMetricFilters::CreateOrUpdateBatchService — WIRED
                // (usage-monitoring slice).
                if (array_key_exists('filters', $this->params) && $this->params['filters'] !== null) {
                    \App\Services\BillableMetricFilters\CreateOrUpdateBatchService::callBang(
                        billableMetric: $locked,
                        filtersParams: (array) $this->params['filters'],
                    );
                }

                // NOTE: Only name and description are editable if billable
                // metric is attached to a plan.
                if (! $locked->attachedToPlan()) {
                    if (array_key_exists('code', $params)) {
                        $locked->code = $params['code'];
                    }
                    if (array_key_exists('aggregation_type', $params)) {
                        $locked->aggregation_type = $params['aggregation_type'];
                    }
                    if (array_key_exists('weighted_interval', $params)) {
                        $locked->weighted_interval = $params['weighted_interval'];
                    }
                    if (array_key_exists('field_name', $params)) {
                        $locked->field_name = $params['field_name'];
                    }
                    if (array_key_exists('recurring', $params)) {
                        $locked->recurring = $params['recurring'];
                    }
                    if (array_key_exists('rounding_function', $params)) {
                        $locked->rounding_function = $params['rounding_function'];
                    }
                    if (array_key_exists('rounding_precision', $params)) {
                        $locked->rounding_precision = $params['rounding_precision'];
                    }
                    if (array_key_exists('expression', $params)) {
                        $locked->expression = $params['expression'];
                    }

                    if (array_key_exists('expression', $params) || array_key_exists('field_name', $params)) {
                        ExpressionCacheService::expireCache(
                            (string) $metric->organization->id,
                            (string) $locked->code,
                        );
                    }
                }

                $errors = $locked->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $locked->save();

                return $locked;
            });

            // TODO(port): SendWebhookJob.perform_after_commit(
            //   "billable_metric.updated", billable_metric) — webhook emission
            //   hook point.

            $result->billable_metric = $metric;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }
}
