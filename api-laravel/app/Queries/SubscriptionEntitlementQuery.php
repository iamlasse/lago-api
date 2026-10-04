<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Models\Entitlement\SubscriptionEntitlement;
use App\Models\Entitlement\SubscriptionEntitlementPrivilege;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlementQuery
 * (app/queries/entitlement/subscription_entitlement_query.rb) — the raw
 * FULL OUTER JOIN merge of a subscription's plan entitlements and its own
 * overrides, honouring the subscription's feature / privilege removals.
 * Statement-for-statement: same CTEs, same ordering, same COALESCEs.
 */
class SubscriptionEntitlementQuery extends BaseService
{
    public function __construct(
        private readonly Organization $organization,
        private readonly array $filters = ['subscription_id' => null, 'plan_id' => null],
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('entitlements');

        $planId = (string) $this->filters['plan_id'];
        $subscriptionId = (string) $this->filters['subscription_id'];

        $features = array_map(
            fn (object $row): SubscriptionEntitlement => new SubscriptionEntitlement(
                organizationId: $row->organization_id,
                entitlementFeatureId: $row->entitlement_feature_id,
                code: $row->code,
                name: $row->name,
                description: $row->description,
                planEntitlementId: $row->plan_entitlement_id,
                subEntitlementId: $row->sub_entitlement_id,
                planId: $row->plan_id,
                subscriptionId: $row->subscription_id,
                orderingDate: $row->ordering_date,
            ),
            DB::select($this->featureSql(), [$planId, $subscriptionId, $subscriptionId]),
        );

        $planEntitlementIds = array_values(array_filter(
            array_map(fn (SubscriptionEntitlement $f): ?string => $f->planEntitlementId, $features),
        ));
        $subEntitlementIds = array_values(array_filter(
            array_map(fn (SubscriptionEntitlement $f): ?string => $f->subEntitlementId, $features),
        ));

        $privileges = array_map(
            fn (object $row): SubscriptionEntitlementPrivilege => new SubscriptionEntitlementPrivilege(
                organizationId: $row->organization_id,
                entitlementFeatureId: $row->entitlement_feature_id,
                code: $row->code,
                value: $row->value,
                valueType: $row->value_type,
                planValue: $row->plan_value,
                subscriptionValue: $row->subscription_value,
                name: $row->name,
                config: $row->config,
                orderingDate: $row->ordering_date,
                planEntitlementId: $row->plan_entitlement_id,
                subEntitlementId: $row->sub_entitlement_id,
                planEntitlementValueId: $row->plan_entitlement_value_id,
                subEntitlementValueId: $row->sub_entitlement_value_id,
            ),
            DB::select(
                $this->privilegeSql(),
                [$this->prepareIds($planEntitlementIds), $this->prepareIds($subEntitlementIds), $subscriptionId],
            ),
        );

        $privilegesByFeatureId = [];

        foreach ($privileges as $privilege) {
            $privilegesByFeatureId[$privilege->entitlementFeatureId][] = $privilege;
        }

        foreach ($features as $feature) {
            $feature->privileges = $privilegesByFeatureId[$feature->entitlementFeatureId] ?? [];
        }

        $result->entitlements = $features;

        return $result;
    }

    private function featureSql(): string
    {
        return <<<'SQL'
            WITH
                plan_entitlements AS (
                    SELECT
                        *
                    FROM
                        entitlement_entitlements
                    WHERE
                        plan_id = ?
                        AND deleted_at IS NULL
                ),
                sub_entitlements AS (
                    SELECT
                        *
                    FROM
                        entitlement_entitlements
                    WHERE
                        subscription_id = ?
                        AND deleted_at IS NULL
                )
            SELECT
                COALESCE(pe.organization_id, se.organization_id) AS organization_id,
                COALESCE(pe.entitlement_feature_id, se.entitlement_feature_id) AS entitlement_feature_id,
                f.code,
                f.name,
                f.description,
                pe.id AS plan_entitlement_id,
                se.id AS sub_entitlement_id,
                pe.plan_id AS plan_id,
                se.subscription_id AS subscription_id,
                COALESCE(pe.created_at, se.created_at) AS ordering_date
            FROM
                plan_entitlements pe
                FULL OUTER JOIN sub_entitlements se ON pe.entitlement_feature_id = se.entitlement_feature_id
                JOIN entitlement_features f ON f.id = COALESCE(pe.entitlement_feature_id, se.entitlement_feature_id)
            WHERE
                f.deleted_at IS NULL
                AND (
                    pe.entitlement_feature_id IS NULL           -- Feature is in sub but not in plan
                    OR pe.entitlement_feature_id NOT IN (       -- Feature is in plan but removed from sub
                        SELECT
                            entitlement_feature_id
                        FROM
                            entitlement_subscription_feature_removals
                        WHERE
                            subscription_id = ?
                            AND entitlement_feature_id IS NOT NULL
                            AND deleted_at IS NULL
                    )
                )
            ORDER BY
                ordering_date
            SQL;
    }

    /**
     * Rails carries a TODO here — removed privileges are not excluded yet;
     * ported as-is.
     */
    private function privilegeSql(): string
    {
        return <<<'SQL'
            WITH
                plan_values AS (
                    SELECT
                        *
                    FROM
                        entitlement_entitlement_values
                    WHERE
                        deleted_at IS NULL
                        AND entitlement_entitlement_id = ANY (?::uuid[])
                ),
                sub_values AS (
                    SELECT
                        *
                    FROM
                        entitlement_entitlement_values
                    WHERE
                        deleted_at IS NULL
                        AND entitlement_entitlement_id = ANY (?::uuid[])
                )
            SELECT
                COALESCE(pv.organization_id, sv.organization_id) AS organization_id,
                p.entitlement_feature_id,
                p.code,
                COALESCE(sv.value, pv.value) AS value,
                pv.value AS plan_value,
                sv.value AS subscription_value,
                p.name,
                p.value_type,
                p.config,
                COALESCE(pv.created_at, sv.created_at) AS ordering_date,
                pv.entitlement_entitlement_id AS plan_entitlement_id,
                sv.entitlement_entitlement_id AS sub_entitlement_id,
                pv.id AS plan_entitlement_value_id,
                sv.id AS sub_entitlement_value_id
            FROM
                plan_values pv
                FULL OUTER JOIN sub_values sv ON pv.entitlement_privilege_id = sv.entitlement_privilege_id
                JOIN entitlement_privileges p ON p.id = COALESCE(pv.entitlement_privilege_id, sv.entitlement_privilege_id)
            WHERE
                p.deleted_at IS NULL
                AND (
                    pv.entitlement_privilege_id IS NULL           -- Privilege is in sub but not in plan
                    OR pv.entitlement_privilege_id NOT IN (       -- Privilege is in plan but removed from sub
                        SELECT
                            entitlement_privilege_id
                        FROM
                            entitlement_subscription_feature_removals
                        WHERE
                            subscription_id = ?
                            AND entitlement_privilege_id IS NOT NULL
                            AND deleted_at IS NULL
                    )
                )
            ORDER BY
                ordering_date
            SQL;
    }

    /**
     * Rails: `prepare_ids` — the `{a,b,c}` array literal for `ANY ($1)`.
     * Ported as a quoted `ARRAY[...]::uuid[]` literal; the ids are uuid
     * primary keys read back from the same statements above.
     *
     * @param  list<string>  $ids
     */
    private function prepareIds(array $ids): string
    {
        if ($ids === []) {
            return '{}';
        }

        return '{'.implode(',', array_map(
            fn (string $id): string => str_replace("'", "''", $id),
            $ids,
        )).'}';
    }
}
