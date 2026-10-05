<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Services\BaseResult;
use App\Models\LifetimeUsage;

/**
 * Port of Rails' LifetimeUsages::FindLastAndNextThresholdsService
 * (app/services/lifetime_usages/find_last_and_next_thresholds_service.rb) —
 * the GraphQL lifetimeUsage thresholds view.
 */
class FindLastAndNextThresholdsService extends \App\Services\BaseService
{
    public function __construct(private readonly LifetimeUsage $lifetimeUsage)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('last_threshold_amount_cents', 'next_threshold_amount_cents', 'next_threshold_ratio');

        $completionResult = UsageThresholdsCompletionService::callBang(lifetimeUsage: $this->lifetimeUsage);

        /** @var list<array<string, mixed>> $thresholds */
        $thresholds = $completionResult->usage_thresholds;

        $reachedIndexes = array_keys(array_filter(
            $thresholds,
            fn (array $threshold): bool => ($threshold['reached_at'] ?? null) !== null,
        ));

        $passedThreshold = null;
        $nextThreshold = null;

        if ($reachedIndexes !== []) {
            $index = $reachedIndexes[count($reachedIndexes) - 1];
            $passedThreshold = $thresholds[$index];
            $nextThreshold = $thresholds[$index + 1] ?? null;
        } else {
            $nextThreshold = $thresholds[0] ?? null;
        }

        $result->last_threshold_amount_cents = $passedThreshold['amount_cents'] ?? null;
        $result->next_threshold_amount_cents = $nextThreshold['amount_cents'] ?? null;
        $result->next_threshold_ratio = $nextThreshold['completion_ratio'] ?? null;

        return $result;
    }
}
