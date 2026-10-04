<?php

declare(strict_types=1);

namespace App\Serializers\V1\Entitlement;

use App\Serializers\Base\ModelSerializer;
use App\Services\Utils\Entitlement as EntitlementUtils;
use App\Models\Entitlement\SubscriptionEntitlementPrivilege;

/**
 * Port of Rails' V1::Entitlement::SubscriptionEntitlementSerializer
 * (app/serializers/v1/entitlement/subscription_entitlement_serializer.rb).
 */
class SubscriptionEntitlementSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'code' => $this->model->code,
            'name' => $this->model->name,
            'description' => $this->model->description,
            'privileges' => $this->privileges(),
            'overrides' => $this->overrides(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function privileges(): array
    {
        $out = [];

        /** @var SubscriptionEntitlementPrivilege $privilege */
        foreach ($this->model->privileges ?? [] as $privilege) {
            $out[] = [
                'code' => $privilege->code,
                'name' => $privilege->name,
                'value_type' => $privilege->valueType,
                'value' => EntitlementUtils::castValue($privilege->value, $privilege->valueType),
                'plan_value' => EntitlementUtils::castValue($privilege->planValue, $privilege->valueType),
                'override_value' => EntitlementUtils::castValue($privilege->subscriptionValue, $privilege->valueType),
                'config' => $privilege->config(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function overrides(): array
    {
        $out = [];

        foreach ($this->model->privileges ?? [] as $privilege) {
            if ($privilege->subscriptionValue !== null) {
                $out[$privilege->code] = EntitlementUtils::castValue(
                    $privilege->subscriptionValue,
                    $privilege->valueType,
                );
            }
        }

        return $out;
    }
}
