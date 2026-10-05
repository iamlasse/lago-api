<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use DateTimeInterface;
use App\Models\LifetimeUsage;
use App\Serializers\Base\ModelSerializer;
use App\Services\LifetimeUsages\UsageThresholdsCompletionService;

/**
 * Port of Rails' V1::LifetimeUsageSerializer
 * (app/serializers/v1/lifetime_usage_serializer.rb).
 */
class LifetimeUsageSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var LifetimeUsage $lifetimeUsage */
        $lifetimeUsage = $this->model;
        $subscription = $lifetimeUsage->subscription;

        $payload = [
            'lago_id' => $lifetimeUsage->id,
            'lago_subscription_id' => $lifetimeUsage->subscription_id,
            'external_subscription_id' => $subscription->external_id,
            'external_historical_usage_amount_cents' => $lifetimeUsage->historical_usage_amount_cents,
            'invoiced_usage_amount_cents' => $lifetimeUsage->invoiced_usage_amount_cents,
            'current_usage_amount_cents' => $lifetimeUsage->current_usage_amount_cents,
            'from_datetime' => $subscription->subscription_at?->toIso8601String(),
            'to_datetime' => now()->toIso8601String(),
        ];

        if ($this->include('usage_thresholds') && $subscription->hasProgressiveBilling()) {
            $result = UsageThresholdsCompletionService::callBang(lifetimeUsage: $lifetimeUsage);

            $payload['usage_thresholds'] = array_map(
                fn (array $row): array => [
                    'amount_cents' => $row['amount_cents'],
                    'completion_ratio' => $row['completion_ratio'],
                    'reached_at' => $row['reached_at'] instanceof DateTimeInterface
                        ? $row['reached_at']->toIso8601String()
                        : $row['reached_at'],
                ],
                $result->usage_thresholds,
            );
        }

        return $payload;
    }
}
