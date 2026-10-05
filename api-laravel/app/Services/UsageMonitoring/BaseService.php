<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

/**
 * Port of Rails' UsageMonitoring::BaseService
 * (app/services/usage_monitoring/base_service.rb) — the threshold-param
 * normalisation shared by the alert create/update services.
 */
abstract class BaseService extends \App\Services\BaseService
{
    /**
     * Rails: #prepare_thresholds — normalises the incoming threshold params
     * into rows for AlertThreshold::create: explicit nils are dropped so the
     * column defaults stand (a null notify_on / code would break NOT NULL).
     *
     * @param  iterable<array<string, mixed>>  $thresholds
     * @return list<array<string, mixed>>
     */
    protected function prepareThresholds(iterable $thresholds, string $organizationId): array
    {
        $rows = [];

        foreach ($thresholds as $thresholdParams) {
            $thresholdParams = (array) $thresholdParams;

            $row = array_merge([
                'organization_id' => $organizationId,
                'code' => null,
                'recurring' => false,
            ], $thresholdParams);

            // An explicit null would break the NOT NULL column, so let its
            // default stand instead.
            if (($row['notify_on'] ?? null) === null) {
                unset($row['notify_on']);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
