<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\EntitlementValue;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;
use App\Services\Failures\NotFoundFailure;
use App\Services\Entitlements\Concerns\ValidatesPrivilegeValue;

/**
 * Port of Rails' Entitlement::PlanEntitlementsUpdateService
 * (app/services/entitlement/plan_entitlements_update_service.rb) — the
 * `entitlements` hash (feature code => privilege code => value) applied to
 * a plan: full sync for POST (partial: false), additive merge for PATCH
 * (partial: true).
 *
 * NOTE: send_webhook gates the activity log too: both represent the same
 * plan.updated event. It is false when invoked from the plan create/update
 * mutations, where the plan service already emits the plan event.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity log middleware (activity_loggable "plan.updated").
 * - TODO(port): SendWebhookJob.perform_after_commit("plan.updated", plan).
 */
class PlanEntitlementsUpdateService extends BaseService
{
    use ValidatesPrivilegeValue;

    public function __construct(
        private readonly ?Organization $organization,
        private readonly ?Plan $plan,
        private readonly array $entitlementsParams,
        private readonly bool $partial,
        private readonly bool $sendWebhook = true,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('entitlements');
        $plan = $this->plan;

        if ($plan === null) {
            return $result->notFoundFailure('plan');
        }

        try {
            DB::transaction(function () use ($plan, $result): void {
                if (! $this->partial) {
                    $this->deleteMissingEntitlements($plan);
                }

                $this->updateEntitlements($plan, $result);
            });

            // TODO(port): SendWebhookJob.perform_after_commit("plan.updated",
            // plan) if send_webhook — the webhook is sent even if no changes
            // were made to the plan.

            // Rails: plan.entitlements.includes(:feature, values: :privilege).reload
            $result->entitlements = Entitlement::query()
                ->where('plan_id', $plan->id)
                ->with('feature', 'values.privilege')->oldest()
                ->get();

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `delete_missing_entitlements` — the plan's entitlements whose
     * feature is not in the params are discarded, their values with them.
     */
    private function deleteMissingEntitlements(Plan $plan): void
    {
        $missing = Entitlement::query()
            ->where('plan_id', $plan->id)
            ->whereHas('feature', fn ($query) => $query->whereNotIn('code', array_keys($this->entitlementsParams)));

        EntitlementValue::query()
            ->whereIn('entitlement_entitlement_id', (clone $missing)->select('id'))
            ->delete();

        $missing->delete();
    }

    /**
     * Rails: `delete_missing_entitlement_values` — on a full update, the
     * entitlement's values whose privilege is not in the feature's params
     * are discarded.
     *
     * @param  array<string, mixed>  $privilegeValues
     */
    private function deleteMissingEntitlementValues(Entitlement $entitlement, array $privilegeValues): void
    {
        if ($privilegeValues === []) {
            return;
        }

        $entitlement->values()
            ->whereHas('privilege', fn ($query) => $query->whereNotIn('code', array_keys($privilegeValues)))
            ->delete();
    }

    /**
     * Rails: `update_entitlements` — one entitlement per feature code.
     */
    private function updateEntitlements(Plan $plan, BaseResult $result): void
    {
        if ($this->entitlementsParams === []) {
            return;
        }

        $organization = $this->organization;

        foreach ($this->entitlementsParams as $featureCode => $privilegeValues) {
            $privilegeValues = is_array($privilegeValues) ? $privilegeValues : [];

            /** @var Feature|null $feature */
            $feature = Feature::query()
                ->where('organization_id', (string) $organization->id)
                ->with('privileges')
                ->where('code', (string) $featureCode)
                ->first();

            if ($feature === null) {
                throw new NotFoundFailure($result, 'feature');
            }

            // Find existing entitlement or create new one
            $entitlement = Entitlement::query()
                ->where('plan_id', $plan->id)
                ->where('entitlement_feature_id', $feature->id)
                ->with('values')
                ->first();

            if ($entitlement === null) {
                $entitlement = Entitlement::query()->create([
                    'organization_id' => $organization->id,
                    'entitlement_feature_id' => $feature->id,
                    'plan_id' => $plan->id,
                ]);
            } elseif (! $this->partial) {
                $this->deleteMissingEntitlementValues($entitlement, $privilegeValues);
            }

            $this->updateEntitlementValues($entitlement, $feature, $privilegeValues, $result);
        }
    }

    /**
     * Rails: `update_entitlement_values` — one value per privilege code.
     *
     * @param  array<string, mixed>  $privilegeValues
     */
    private function updateEntitlementValues(
        Entitlement $entitlement,
        Feature $feature,
        array $privilegeValues,
        BaseResult $result,
    ): void {
        if ($privilegeValues === []) {
            return;
        }

        foreach ($privilegeValues as $privilegeCode => $value) {
            $privilege = $feature->privileges->first(
                fn ($record): bool => $record->code === (string) $privilegeCode,
            );

            if ($privilege === null) {
                throw new NotFoundFailure($result, 'privilege');
            }

            $entitlementValue = $entitlement->values->first(
                fn ($record): bool => $record->entitlement_privilege_id === $privilege->id,
            );

            if ($entitlementValue !== null) {
                $entitlementValue->value = $this->validatePrivilegeValue($value, $privilege, $result);
                $entitlementValue->save();
            } else {
                $this->createEntitlementValue($entitlement, $privilege, $value, $result);
            }
        }
    }

    /** Rails: `create_entitlement_value`. */
    private function createEntitlementValue(
        Entitlement $entitlement,
        $privilege,
        $value,
        BaseResult $result,
    ): void {
        EntitlementValue::query()->create([
            'organization_id' => $entitlement->organization_id,
            'entitlement_entitlement_id' => $entitlement->id,
            'entitlement_privilege_id' => $privilege->id,
            'value' => $this->validatePrivilegeValue($value, $privilege, $result),
        ]);
    }
}
