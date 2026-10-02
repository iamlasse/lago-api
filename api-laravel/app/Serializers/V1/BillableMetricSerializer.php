<?php

declare(strict_types=1);

namespace App\Serializers\V1;

use App\Serializers\Base\ModelSerializer;
use App\Serializers\V1\Concerns\FormatsDatetime;

/**
 * Port of Rails' V1::BillableMetricSerializer
 * (app/serializers/v1/billable_metric_serializer.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): filters — V1::BillableMetricFilterSerializer over
 *   model.filters (BillableMetricFilter model is a later slice); emitted as
 *   an empty collection.
 */
class BillableMetricSerializer extends ModelSerializer
{
    use FormatsDatetime;

    public function serialize(): array
    {
        $payload = [
            'lago_id' => $this->model->id,
            'name' => $this->model->name,
            'code' => $this->model->code,
            'description' => $this->model->description,
            'aggregation_type' => $this->model->aggregation_type?->label(),
            'weighted_interval' => $this->model->weighted_interval?->value,
            'recurring' => $this->model->recurring,
            'rounding_function' => $this->model->rounding_function?->value,
            'rounding_precision' => $this->model->rounding_precision,
            'created_at' => $this->serializeDatetime($this->model->created_at),
            'field_name' => $this->model->field_name,
            'expression' => $this->model->expression,
        ];

        if ($this->include('counters')) {
            $payload = [...$payload, ...$this->counters()];
        }

        return [...$payload, ...$this->filters()];
    }

    /** @return array<string, mixed> */
    protected function counters(): array
    {
        return [
            'active_subscriptions_count' => 0,
            'draft_invoices_count' => 0,
            'plans_count' => 0,
        ];
    }

    /** @return array<string, mixed> */
    protected function filters(): array
    {
        // TODO(port): CollectionSerializer over model.filters with
        // V1::BillableMetricFilterSerializer (key + sorted values).
        return ['filters' => []];
    }
}
