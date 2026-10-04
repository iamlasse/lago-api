<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\EntitlementValue;
use Illuminate\Support\Facades\DB;
use App\Models\SubscriptionFeatureRemoval;
use App\Services\Failures\NotFoundFailure;
use App\Services\Utils\Entitlement as EntitlementUtils;
use App\Services\Entitlements\Concerns\ValidatesPrivilegeValue;

/**
 * Port of Rails' Entitlement::SubscriptionEntitlementCoreUpdateService
 * (app/services/entitlement/subscription_entitlement_core_update_service.rb).
 *
 * This inner service updates a single entitlement. It intentionally adds no
 * activity log entries, returns no data and sends no webhooks — the outer
 * services handle that. Unlike the outer services it is invoked with
 * `call!` (BaseService::callBang) and lets the raised failures surface.
 */
class SubscriptionEntitlementCoreUpdateService extends BaseService
{
    use ValidatesPrivilegeValue;

    /** The result the failures are attached to (threaded through the body). */
    private BaseResult $result;

    public function __construct(
        private readonly Subscription $subscription,
        private readonly Plan $plan,
        private readonly Feature $feature,
        private readonly ?Entitlement $planEntitlement,
        private readonly ?Entitlement $subEntitlement,
        private readonly array $privilegeParams,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $this->result = static::makeResult();

        DB::transaction(function (): void {
            $this->processSingleEntitlement();
        });

        return $this->result;
    }

    private function processSingleEntitlement(): void
    {
        if ($this->planEntitlement === null && $this->subEntitlement === null) {
            // Neither the plan nor the subscription carries the feature: the
            // override creates the entitlement, and any previous removal
            // tombstone is lifted.
            SubscriptionFeatureRemoval::query()->where('subscription_id', $this->subscription->id)
                ->where('entitlement_feature_id', $this->feature->id)
                ->update(['deleted_at' => now()]);
            $this->createEntitlementAndValuesForSubscription();

            return;
        }

        if ($this->planEntitlement !== null && $this->privilegeParamsSameAsPlan($this->planEntitlement)) {
            // Restore the plan default by removing all overrides.
            $this->subEntitlement?->values()->update(['deleted_at' => now()]);
            $this->subEntitlement?->delete();

            SubscriptionFeatureRemoval::query()
                ->where('subscription_id', $this->subscription->id)
                ->where(function ($query): void {
                    $featureId = $this->feature->id;

                    $query->where('entitlement_feature_id', $featureId)
                        ->orWhereIn('entitlement_privilege_id', $this->feature->privileges()->select('id'));
                })
                ->update(['deleted_at' => now()]);

            return;
        }

        SubscriptionFeatureRemoval::query()->where('subscription_id', $this->subscription->id)
            ->where('entitlement_feature_id', $this->feature->id)
            ->update(['deleted_at' => now()]);

        $subEntitlement = $this->subEntitlement ?? $this->createEntitlementForSubscription();

        if (! $this->partial) {
            $this->removeMissingEntitlementValues($this->planEntitlement, $subEntitlement);
        }

        $this->updateValuesForSubscription($this->planEntitlement, $subEntitlement);
    }

    private function createEntitlementForSubscription(): Entitlement
    {
        return Entitlement::query()->create([
            'organization_id' => $this->subscription->organization_id,
            'subscription_id' => $this->subscription->id,
            'entitlement_feature_id' => $this->feature->id,
        ]);
    }

    private function createEntitlementAndValuesForSubscription(): Entitlement
    {
        $entitlement = $this->createEntitlementForSubscription();

        foreach ($this->privilegeParams as $privilegeCode => $value) {
            $privilege = $this->findPrivilegeOrFail((string) $privilegeCode);

            $this->createEntitlementValue($entitlement, $privilege, $value);
        }

        return $entitlement;
    }

    /**
     * Rails: `remove_missing_entitlement_values` — on a full update, the
     * privileges present on the plan's values or the subscription's values
     * but absent from the params are dropped from the subscription; when
     * the privilege came from the plan (and was not already tombstoned), a
     * removal is recorded.
     */
    private function removeMissingEntitlementValues(?Entitlement $planEntitlement, Entitlement $subEntitlement): void
    {
        $planPrivilegeCodes = $planEntitlement?->values
            ? array_filter($planEntitlement->values->map(fn ($v) => $v->privilege?->code)->all())
            : [];
        $subPrivilegeCodes = $subEntitlement->values
            ? array_filter($subEntitlement->values->map(fn ($v) => $v->privilege?->code)->all())
            : [];

        // (plan codes + subscription codes) - param keys, deduplicated.
        $privilegeCodesToRemove = array_values(array_unique(array_diff(
            array_merge($planPrivilegeCodes, $subPrivilegeCodes),
            array_map('strval', array_keys($this->privilegeParams)),
        )));

        foreach ($privilegeCodesToRemove as $privilegeCode) {
            $subValue = $subEntitlement->values->first(fn ($v) => $v->privilege?->code === $privilegeCode);
            $subValue?->delete();

            $planValue = $planEntitlement?->values?->first(fn ($v) => $v->privilege?->code === $privilegeCode);

            if ($planValue !== null) {
                $removalExists = SubscriptionFeatureRemoval::query()
                    ->where('organization_id', $this->subscription->organization_id)
                    ->where('subscription_id', $this->subscription->id)
                    ->where('entitlement_privilege_id', $planValue->privilege->id)
                    ->exists();

                if (! $removalExists) {
                    SubscriptionFeatureRemoval::query()->create([
                        'organization_id' => $this->subscription->organization_id,
                        'subscription_id' => $this->subscription->id,
                        'entitlement_privilege_id' => $planValue->privilege->id,
                    ]);
                }
            }
        }
    }

    /**
     * Rails: `update_values_for_subscription` — the merge semantics: a plan
     * value equal to the param clears the subscription override (and its
     * removal tombstones); an absent value is created; a differing override
     * is updated.
     */
    private function updateValuesForSubscription(?Entitlement $planEntitlement, Entitlement $subEntitlement): void
    {
        foreach ($this->privilegeParams as $privilegeCode => $value) {
            $privilege = $this->findPrivilegeOrFail((string) $privilegeCode);

            $planValue = $planEntitlement?->values?->first(fn ($v) => $v->privilege?->code === $privilege->code);
            $subValue = $subEntitlement->values->first(fn ($v) => $v->privilege?->code === $privilege->code);

            if ($planValue !== null
                && $this->valueIsTheSame($privilege->value_type, $value, $planValue->value)) {
                $subValue?->delete();
                $this->deleteAllPrivilegeEntitlementRemovals($privilege->id);
            } elseif ($subValue === null) {
                $this->deleteAllPrivilegeEntitlementRemovals($privilege->id);

                $this->createEntitlementValue($subEntitlement, $privilege, $value);
            } elseif (! $this->valueIsTheSame($privilege->value_type, $value, $subValue->value)) {
                $subValue->value = $this->validatePrivilegeValue($value, $privilege, $this->result);
                $subValue->save();
            }
        }
    }

    private function valueIsTheSame(?string $type, mixed $value1, mixed $value2): bool
    {
        return EntitlementUtils::sameValue($type, $value1, $value2);
    }

    private function deleteAllPrivilegeEntitlementRemovals(string $privilegeId): void
    {
        SubscriptionFeatureRemoval::query()->where('subscription_id', $this->subscription->id)
            ->where('entitlement_privilege_id', $privilegeId)
            ->update(['deleted_at' => now()]);
    }

    private function createEntitlementValue(Entitlement $entitlement, Privilege $privilege, mixed $value): void
    {
        EntitlementValue::query()->create([
            'organization_id' => $this->subscription->organization_id,
            'entitlement_entitlement_id' => $entitlement->id,
            'entitlement_privilege_id' => $privilege->id,
            'value' => (string) $this->validatePrivilegeValue($value, $privilege, $this->result),
        ]);
    }

    /**
     * Rails: `find_privilege!` — an unknown privilege code surfaces as the
     * not_found envelope (resource "privilege") in the outer service.
     */
    private function findPrivilegeOrFail(string $privilegeCode): Privilege
    {
        $privilege = $this->feature->privileges->first(
            fn (Privilege $p): bool => $p->code === $privilegeCode,
        );

        if ($privilege === null) {
            throw new NotFoundFailure($this->result, 'privilege');
        }

        return $privilege;
    }

    /**
     * Rails: `privilege_params_same_as_plan?` — the param set matches the
     * plan's privileges exactly and every value equals the plan's.
     */
    private function privilegeParamsSameAsPlan(Entitlement $planEntitlement): bool
    {
        $paramKeys = array_map('strval', array_keys($this->privilegeParams));
        sort($paramKeys);

        $planCodes = $planEntitlement->values
            ? $planEntitlement->values
                ->map(fn ($v) => $v->privilege?->code)
                ->filter()
                ->sort()
                ->values()
                ->all()
            : [];

        if ($paramKeys !== $planCodes) {
            return false;
        }

        return $planEntitlement->values->every(function ($v): bool {
            $code = $v->privilege?->code;

            if ($code === null || ! array_key_exists($code, $this->privilegeParams)) {
                return false;
            }

            return $this->valueIsTheSame($v->privilege->value_type, $v->value, $this->privilegeParams[$code]);
        });
    }
}
