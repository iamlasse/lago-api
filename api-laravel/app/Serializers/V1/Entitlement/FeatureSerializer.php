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

        // The Rails association carries no ORDER BY, so its response order is
        // the DB's heap order — effectively creation order. Pin that
        // deterministically: creation time, then code as the tiebreak for
        // privileges minted in the same request (matches every captured
        // golden; see scripts/contract/README.md finding 22).
        $privileges = $this->model->privileges
            ->sortBy([['created_at', 'asc'], ['code', 'asc']])
            ->values();

        foreach ($privileges as $privilege) {
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
