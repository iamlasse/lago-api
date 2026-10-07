<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\Feature;
use App\Models\Privilege;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\EntitlementValue;
use Illuminate\Support\Facades\DB;
use App\Services\Utils\Entitlement;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Entitlement::FeatureUpdateService
 * (app/services/entitlement/feature_update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "feature.updated").
 * - TODO(port): the plan.updated activity logs + webhooks for feature.plans
 *   and SendWebhookJob("feature.updated", feature) — Rails emits them after
 *   commit even when nothing changed; the emission points are marked below.
 */
class UpdateService extends BaseService
{
    public function __construct(
        private readonly ?Feature $feature,
        private readonly array $params,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('feature');
        $feature = $this->feature;
        $params = $this->params;

        if ($feature === null) {
            return $result->notFoundFailure('feature');
        }

        if (Entitlement::privilegeCodeIsDuplicated($params['privileges'] ?? null)) {
            return $result->singleValidationFailure('value_is_duplicated', 'privilege.code');
        }

        try {
            DB::transaction(function () use ($feature, $params, $result): void {
                $this->updateFeatureAttributes($feature, $params);

                if (! $this->partial) {
                    $this->deleteMissingPrivileges($feature, $params);
                }

                $this->updatePrivileges($feature, $params, $result);

                $feature->save();
            });

            // TODO(port): webhooks — Rails: feature.plans.each do |plan|
            //   Utils::ActivityLog.produce_after_commit(plan, "plan.updated")
            //   jobs << SendWebhookJob.new("plan.updated", plan)
            // end; perform_all_later(jobs) + SendWebhookJob.perform_later(
            //   "feature.updated", feature) — after commit, even when nothing
            //   changed.

            // Rails reaches for `feature.privileges` through the loaded
            // association target — `feature.privileges.new` appends to it and
            // `privilege.discard!` marks in-memory records, so the serializer
            // sees the post-update collection. Eloquent's make() + child
            // save() and the deleteMissingPrivileges bulk delete leave the
            // loaded collection stale, so reload it after commit (same
            // stale-association shape as the auth_org webhook_url fix).
            $feature->setRelation(
                'privileges',
                $feature->privileges()->oldest()->orderBy('code')->get(),
            );

            $result->feature = $feature;

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /** Rails: `update_feature_attributes`. */
    private function updateFeatureAttributes(Feature $feature, array $params): void
    {
        if (array_key_exists('name', $params)) {
            $feature->name = $params['name'];
        }

        if (array_key_exists('description', $params)) {
            $feature->description = $params['description'];
        }
    }

    /**
     * Rails: `update_privileges` — existing privileges are updated in place
     * (name, and select_options unioned into config), unknown codes are
     * created.
     *
     * @param  array<string, mixed>  $params
     */
    private function updatePrivileges(Feature $feature, array $params, BaseResult $result): void
    {
        if (empty($params['privileges'])) {
            return;
        }

        foreach ($params['privileges'] as $privilegeParam) {
            $privilegeParams = is_array($privilegeParam) ? $privilegeParam : [];

            $privilege = $feature->privileges->first(
                fn (Privilege $record): bool => $record->code === ($privilegeParams['code'] ?? null),
            );

            if ($privilege === null) {
                $this->createPrivilege($feature, $privilegeParams, $result);
            } else {
                if (array_key_exists('name', $privilegeParams)) {
                    $privilege->name = $privilegeParams['name'];
                }

                $config = is_array($privilegeParams['config'] ?? null) ? $privilegeParams['config'] : null;

                if (isset($config['select_options'])) {
                    $privilegeConfig = $privilege->config ?? [];
                    $privilegeConfig['select_options'] = array_values(array_unique(array_merge(
                        $privilegeConfig['select_options'] ?? [],
                        (array) $config['select_options'],
                    )));
                    $privilege->config = $privilegeConfig;
                }

                $this->savePrivilege($privilege, $result);
            }
        }
    }

    /**
     * Rails: `create_privilege`.
     *
     * @param  array<string, mixed>  $privilegeParams
     */
    private function createPrivilege(Feature $feature, array $privilegeParams, BaseResult $result): void
    {
        $privilege = $feature->privileges()->make([
            'organization_id' => $feature->organization_id,
            'code' => isset($privilegeParams['code']) && is_string($privilegeParams['code'])
                ? mb_trim($privilegeParams['code'])
                : null,
            'name' => $privilegeParams['name'] ?? null,
        ]);

        if (array_key_exists('value_type', $privilegeParams)) {
            $privilege->value_type = $privilegeParams['value_type'];
        }

        if (array_key_exists('config', $privilegeParams)) {
            $privilege->config = $privilegeParams['config'];
        }

        $this->savePrivilege($privilege, $result);
    }

    /** Rails: `privilege.save!` — the RecordInvalid mapping. */
    private function savePrivilege(Privilege $privilege, BaseResult $result): void
    {
        $errors = $privilege->validateAttributes();

        if ($errors !== []) {
            // Rails prefixes the field names — you can get a "code" error
            // from the feature or the privilege, so the privilege's keys
            // are namespaced.
            $prefixed = [];

            foreach ($errors as $field => $codes) {
                $prefixed['privilege.'.$field] = $codes;
            }

            $result->validationFailure($prefixed)->raiseIfError();
        }

        $privilege->save();
    }

    /**
     * Rails: `delete_missing_privileges` — privileges present in the
     * database but not in the params are discarded, their entitlement
     * values with them.
     *
     * @param  array<string, mixed>  $params
     */
    private function deleteMissingPrivileges(Feature $feature, array $params): void
    {
        $missingPrivilegeCodes = array_values(array_diff(
            $feature->privileges()->pluck('code')->all(),
            array_column(is_array($params['privileges'] ?? null) ? $params['privileges'] : [], 'code'),
        ));

        EntitlementValue::query()
            ->whereIn(
                'entitlement_privilege_id',
                $feature->privileges()->whereIn('code', $missingPrivilegeCodes)->select('id'),
            )
            ->delete();

        $feature->privileges()->whereIn('code', $missingPrivilegeCodes)->delete();
    }
}
