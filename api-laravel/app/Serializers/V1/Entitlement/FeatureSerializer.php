<?php

declare(strict_types=1);

namespace App\Serializers\V1\Entitlement;

use App\Serializers\Base\ModelSerializer;

/**
 * Port of Rails' V1::Entitlement::FeatureSerializer
 * (app/serializers/v1/entitlement/feature_serializer.rb).
 */
class FeatureSerializer extends ModelSerializer
{
    /** @return array<string, mixed> */
    public function serialize(): array
    {
        return [
            'code' => $this->model->code,
            'name' => $this->model->name,
            'description' => $this->model->description,
            'privileges' => $this->privileges(),
            'created_at' => $this->model->created_at->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function privileges(): array
    {
        $out = [];

        foreach ($this->model->privileges as $privilege) {
            $out[] = [
                'code' => $privilege->code,
                'name' => $privilege->name,
                'value_type' => $privilege->value_type,
                'config' => $privilege->config,
            ];
        }

        return $out;
    }
}
