<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring\Alerts;

use RuntimeException;
use App\Models\Wallet;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;
use App\Services\UsageMonitoring\CreateAlertService;

/**
 * Port of Rails' UsageMonitoring::Alerts::CreateBatchService
 * (app/services/usage_monitoring/alerts/create_batch_service.rb) — the
 * all-or-nothing `alerts` array branch of the alert create endpoints. Each
 * alert's failure is captured into `errors[index]`; any failure rolls back
 * the whole batch.
 */
class CreateBatchService extends \App\Services\UsageMonitoring\BaseService
{
    private ?array $preloaded = null;

    public function __construct(
        private readonly ?Organization $organization,
        private readonly Subscription|Wallet|null $alertable,
        private readonly array $alertsParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('alerts', 'errors');

        if ($this->organization === null) {
            return $result->notFoundFailure('organization');
        }

        if ($this->alertable === null) {
            return $result->notFoundFailure('alertable');
        }

        if ($this->alertsParams === []) {
            return $result->singleValidationFailure('no_alerts', 'alerts');
        }

        $result->alerts = [];
        $result->errors = [];

        try {
            DB::transaction(function () use ($result): void {
                foreach ($this->alertsParams as $index => $alertParams) {
                    $alertParams = (array) $alertParams;

                    try {
                        DB::transaction(function () use ($result, $alertParams, $index): void {
                            $createResult = CreateAlertService::call(
                                organization: $this->organization,
                                alertable: $this->alertable,
                                params: $this->billableMetricParams($alertParams),
                            );

                            if ($createResult->success()) {
                                $alerts = $result->alerts ?? [];
                                $alerts[] = $createResult->alert;
                                $result->alerts = $alerts;

                                return;
                            }

                            $errors = $result->errors ?? [];
                            $errors[$index] = [
                                'params' => $alertParams,
                                'errors' => $createResult->getError()?->getMessage(),
                            ];
                            $result->errors = $errors;

                            throw new RollbackSavepoint();
                        });
                    } catch (RollbackSavepoint) {
                        continue;
                    }
                }

                if ($result->errors !== []) {
                    throw new RollbackSavepoint();
                }
            });
        } catch (RollbackSavepoint) {
            // Rails: the outer transaction rolls back when any member failed.
        }

        if ($result->errors !== []) {
            $result->alerts = [];

            return $result->validationFailure($result->errors);
        }

        return $result;
    }

    /**
     * Rails: #preloaded_billable_metrics / #billable_metric_params — resolve
     * billable_metric_code / billable_metric_id once for the whole batch.
     *
     * @param  array<string, mixed>  $alertParams
     * @return array<string, mixed>
     */
    private function billableMetricParams(array $alertParams): array
    {
        $metrics = $this->preloadedBillableMetrics();

        if (! empty($alertParams['billable_metric_code'])) {
            $billableMetric = $metrics['by_code'][$alertParams['billable_metric_code']] ?? null;

            if ($billableMetric !== null) {
                $alertParams['billable_metric'] = $billableMetric;
            }
        } elseif (! empty($alertParams['billable_metric_id'])) {
            $billableMetric = $metrics['by_id'][(string) $alertParams['billable_metric_id']] ?? null;

            if ($billableMetric !== null) {
                $alertParams['billable_metric'] = $billableMetric;
            }
        }

        return $alertParams;
    }

    /** @return array{by_code: array<string, BillableMetric>, by_id: array<string, BillableMetric>} */
    private function preloadedBillableMetrics(): array
    {
        if ($this->preloaded === null) {
            $codes = [];
            $ids = [];

            foreach ($this->alertsParams as $p) {
                $p = (array) $p;

                if (! empty($p['billable_metric_code'])) {
                    $codes[] = $p['billable_metric_code'];
                }

                if (! empty($p['billable_metric_id'])) {
                    $ids[] = $p['billable_metric_id'];
                }
            }

            $query = $this->organization->billableMetrics();

            $metrics = $query->where(fn ($q) => $q->whereIn('code', array_unique($codes))
                ->orWhereIn('id', array_unique($ids)))
                ->get();

            $this->preloaded = [
                'by_code' => $metrics->keyBy('code')->all(),
                'by_id' => $metrics->keyBy(fn (BillableMetric $m): string => (string) $m->id)->all(),
            ];
        }

        return $this->preloaded;
    }
}

/** Internal rollback marker (Rails' ActiveRecord::Rollback inside the savepoint). */
class RollbackSavepoint extends RuntimeException {}
