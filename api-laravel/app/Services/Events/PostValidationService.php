<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Events::PostValidationService
 * (app/services/events/post_validation_service.rb) — scans the refreshed
 * `last_hour_events_mv` materialized view for the organization's invalid
 * events (unknown billable metric code, missing/invalid aggregation
 * property, invalid filter values) and delivers the `events.errors`
 * webhook listing the offending transaction ids.
 */
class PostValidationService extends BaseService
{
    public function __construct(private readonly Organization $organization)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('errors');

        $errors = [
            'invalid_code' => $this->processQuery($this->invalidCodeQuery()),
            'missing_aggregation_property' => $this->processQuery($this->missingAggregationPropertyQuery()),
            'invalid_filter_values' => $this->processQuery($this->invalidFilterValuesQuery()),
        ];

        if ($errors['invalid_code'] !== []
            || $errors['missing_aggregation_property'] !== []
            || $errors['invalid_filter_values'] !== []
        ) {
            $this->deliverWebhook($errors);
        }

        $result->errors = $errors;

        return $result;
    }

    private function invalidCodeQuery(): string
    {
        return <<<'SQL'
            SELECT DISTINCT transaction_id
            FROM last_hour_events_mv
            WHERE organization_id = ?
              AND billable_metric_code IS NULL
            SQL;
    }

    private function missingAggregationPropertyQuery(): string
    {
        return <<<'SQL'
            SELECT DISTINCT transaction_id
            FROM last_hour_events_mv
            WHERE organization_id = ?
              AND (
                (
                  field_name_mandatory = 't'
                  AND field_value IS NULL
                )
                OR (
                  numeric_field_mandatory = 't'
                  AND is_numeric_field_value = 'f'
                )
              )
            SQL;
    }

    private function invalidFilterValuesQuery(): string
    {
        return <<<'SQL'
            SELECT DISTINCT transaction_id
            FROM last_hour_events_mv
            WHERE organization_id = ?
              AND has_filter_keys = 't'
              AND has_valid_filter_values = 'f'
            SQL;
    }

    /** @return list<string> */
    private function processQuery(string $sql): array
    {
        return array_map(
            fn ($row): string => (string) $row->transaction_id,
            DB::select($sql, [$this->organization->id])
        );
    }

    /** @param array<string, list<string>> $errors */
    private function deliverWebhook(array $errors): void
    {
        SendWebhookJob::performLater('events.errors', $this->organization, ['errors' => $errors]);
    }
}
