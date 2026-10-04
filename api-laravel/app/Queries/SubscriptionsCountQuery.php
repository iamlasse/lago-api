<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Entitlement::Feature::SubscriptionsCountQuery
 * (app/queries/entitlement/feature/subscriptions_count_query.rb) — the
 * feature_id => active/pending subscription count map behind the GraphQL
 * `subscriptionsCount` field, batched for the whole page of features.
 */
class SubscriptionsCountQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $filters = ['feature_ids' => []],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('features');

        $featureIds = (array) ($this->filters['feature_ids'] ?? []);

        $rows = DB::select($this->subscriptionsCountSql($featureIds), [
            $this->organization->id,
        ]);

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->feature_id] = (int) $row->count;
        }

        $result->features = $counts;

        return $result;
    }

    /**
     * Rails builds the statement with sanitize_sql_array over the feature
     * ids; the ids are organization-generated uuid primary keys, quoted the
     * same way before being inlined into the ARRAY literal.
     *
     * @param  list<string>  $featureIds
     */
    private function subscriptionsCountSql(array $featureIds): string
    {
        $ids = $featureIds === []
            ? 'ARRAY[]::uuid[]'
            : 'ARRAY['.implode(', ', array_map(
                fn (string $id): string => "'".str_replace("'", "''", $id)."'",
                $featureIds,
            )).']::uuid[]';

        return <<<SQL
            WITH
              plan_features AS (
                SELECT
                  plan_id,
                  entitlement_feature_id
                FROM
                  entitlement_entitlements
                WHERE
                  plan_id IS NOT NULL
                  AND entitlement_feature_id = ANY ({$ids})
              ),
              plan_subscriptions AS (
                SELECT
                  coalesce(plans.parent_id, plan_id) AS plan_id,
                  count(*) AS count
                FROM
                  subscriptions
                  INNER JOIN plans ON plans.id = subscriptions.plan_id
                WHERE
                  plans.deleted_at IS NULL
                  AND plans.organization_id = ?
                  AND subscriptions.status IN (0, 1)
                GROUP BY
                  coalesce(plans.parent_id, plan_id)
              )
            SELECT
              plan_features.entitlement_feature_id AS feature_id,
              SUM(plan_subscriptions.count) AS count
            FROM
              plan_features
              INNER JOIN plan_subscriptions ON plan_features.plan_id = plan_subscriptions.plan_id
            GROUP BY
              plan_features.entitlement_feature_id
            SQL;
    }
}
