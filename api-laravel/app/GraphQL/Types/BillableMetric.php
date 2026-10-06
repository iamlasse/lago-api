<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Enums\AggregationType;
use App\Models\BillableMetric as BillableMetricModel;

/**
 * Field resolvers for the frozen SDL's `BillableMetric` type (port of Rails'
 * Types::BillableMetrics::Object enum fields). The model casts these columns
 * to the PHP enums; the wire carries the Rails enum names
 * (`count_agg`/`seconds`/`round`), so the int-backed aggregation type is
 * mapped through its label and the string-backed columns through their raw
 * values.
 */
class BillableMetric
{
    /** Rails: the aggregation_type enum name — the column stores the integer position. */
    public function aggregationType(BillableMetricModel $root): ?string
    {
        $raw = $root->getRawOriginal('aggregation_type');

        return $raw === null ? null : AggregationType::from((int) $raw)->label();
    }

    /** Rails: the weighted_interval PG enum label. */
    public function weightedInterval(BillableMetricModel $root): ?string
    {
        return $root->getRawOriginal('weighted_interval');
    }

    /** Rails: the rounding_function PG enum label. */
    public function roundingFunction(BillableMetricModel $root): ?string
    {
        return $root->getRawOriginal('rounding_function');
    }

    /**
     * Rails: object.integration_mappings (optionally narrowed by
     * integration_id) — the billable metric's mappings over the frozen
     * integration_mappings table.
     */
    public function integrationMappings(BillableMetricModel $root, array $args = []): ?array
    {
        $query = \App\Models\IntegrationMappings\BaseMapping::query()
            ->where('mappable_type', 'BillableMetric')
            ->where('mappable_id', $root->id);

        if (($args['integrationId'] ?? null) !== null) {
            $query->where('integration_id', $args['integrationId']);
        }

        return $query->get()->all();
    }
}
