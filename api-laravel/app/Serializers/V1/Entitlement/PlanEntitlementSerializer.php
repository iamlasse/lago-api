<?php

declare(strict_types=1);

namespace App\Serializers\V1\Entitlement;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Entitlement::PlanEntitlementSerializer
 * (app/serializers/v1/entitlement/plan_entitlement_serializer.rb).
 */
class PlanEntitlementSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'code' => $this->model->feature->code,
            'name' => $this->model->feature->name,
            'description' => $this->model->feature->description,
            'privileges' => $this->privileges(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function privileges(): array
    {
        $out = [];

        foreach ($this->model->values as $entitlementValue) {
            $privilege = $entitlementValue->privilege;

            if ($privilege === null) {
                continue;
            }

            $out[] = [
                'code' => $privilege->code,
                'name' => $privilege->name,
                'value_type' => $privilege->value_type,
                'value' => \App\Services\Utils\Entitlement::castValue(
                    $entitlementValue->value,
                    $privilege->value_type,
                ),
                'config' => $privilege->config,
            ];
        }

        return $out;
    }
}
