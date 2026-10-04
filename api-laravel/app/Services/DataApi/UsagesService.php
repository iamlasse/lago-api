<?php

declare(strict_types=1);

namespace App\Services\DataApi;

use App\Services\BaseResult;
use App\Models\BillableMetric;

/**
 * Port of Rails' DataApi::UsagesService (app/services/data_api/usages_service.rb).
 *
 * Unlike every other Data API service this one has NO premium gate — instead
 * the params are filtered by license (filtered_params): premium organizations
 * pass filters through (defaulting the granularity to daily), everyone else
 * is pinned to daily granularity over the last 30 days with only the
 * billable_metric_code filter honored. Each returned usage is annotated with
 * is_billable_metric_deleted from the organization's discarded billable
 * metrics.
 */
class UsagesService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = static::makeResult('usages');

        $response = $this->httpClient()->get(headers: $this->headers(), params: $this->filteredParams());

        $discardedCodes = $this->discardedBillableMetricsCodes();

        $result->usages = array_map(
            static function (mixed $usage) use ($discardedCodes): mixed {
                if (is_array($usage)) {
                    // Rails: usage["is_billable_metric_deleted"] =
                    //   discarded_billable_metrics_codes.include?(code)
                    $usage['is_billable_metric_deleted'] = in_array(
                        $usage['billable_metric_code'] ?? null,
                        $discardedCodes,
                        true,
                    );
                }

                return $usage;
            },
            is_array($response) ? $response : [],
        );

        return $result;
    }

    /**
     * Port of #filtered_params — see the class docblock.
     *
     * @return array<string, mixed>
     */
    protected function filteredParams(): array
    {
        if ($this->premium()) {
            // Rails: params.dup.tap { |filtered| filtered[:time_granularity] ||= "daily" }
            $filtered = $this->params;
            $filtered['time_granularity'] ??= 'daily';

            return $filtered;
        }

        // Rails: Date.current - 30.days
        $filtered = [
            'time_granularity' => 'daily',
            'start_of_period_dt' => now()->subDays(30)->toDateString(),
        ];

        $billableMetricCode = $this->params['billable_metric_code'] ?? null;

        if (is_string($billableMetricCode) && $billableMetricCode !== '') {
            $filtered['billable_metric_code'] = $billableMetricCode;
        }

        return $filtered;
    }

    /**
     * Port of #discarded_billable_metrics_codes — the codes of the
     * organization's soft-deleted billable metrics.
     *
     * @return list<string>
     */
    protected function discardedBillableMetricsCodes(): array
    {
        return BillableMetric::onlyTrashed()
            ->where('organization_id', $this->organization->id)
            ->pluck('code')
            ->all();
    }

    protected function actionPath(): string
    {
        return "usages/{$this->organization->id}/";
    }
}
