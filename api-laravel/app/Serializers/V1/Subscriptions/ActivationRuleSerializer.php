<?php

declare(strict_types=1);

namespace App\Serializers\V1\Subscriptions;

use App\Serializers\Base\ModelSerializer;
use App\Models\Subscription\ActivationRule;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::Subscriptions::ActivationRuleSerializer
 * (app/serializers/v1/subscriptions/activation_rule_serializer.rb).
 */
class ActivationRuleSerializer extends ModelSerializer
{
    use FormatsDatetime;

    /** @return array<string, mixed> */
    public function serialize(): array
    {
        /** @var ActivationRule $rule */
        $rule = $this->model;

        return [
            'lago_id' => $rule->id,
            'type' => $rule::stiName(),
            'timeout_hours' => $rule->timeout_hours,
            'status' => $rule->status,
            'expires_at' => $rule->expires_at !== null ? $rule->expires_at->toIso8601String() : null,
            'created_at' => $rule->created_at->toIso8601String(),
            'updated_at' => $rule->updated_at->toIso8601String(),
        ];
    }
}
