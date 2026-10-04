<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Contract;
use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

use function array_diff;
use function array_key_exists;

/**
 * Port of Rails' Contracts::UpdateService
 * (app/services/contracts/update_service.rb) — edits a contract's
 * authoring fields. A pending contract is fully editable, and changing its
 * plan re-materializes its rate cards onto the contract. Once active, its
 * pricing and schedule are signed: only the administrative fields in
 * EDITABLE_WHILE_ACTIVE can change, and a locked field is accepted only
 * when it carries the value already stored.
 */
class UpdateService extends BaseService
{
    /** An allowlist, so a field added later starts locked on active contracts. */
    private const EDITABLE_WHILE_ACTIVE = [
        'name',
        'ended_at',
        'purchase_order_number',
        'billing_entity_id',
        'consolidate_invoice',
        'payment_method_id',
        'payment_method_type',
    ];

    public function __construct(
        private readonly ?Contract $contract,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('contract');
        $contract = $this->contract;
        $params = $this->params;

        try {
            if ($contract === null) {
                return $result->notFoundFailure('contract');
            }

            if (! in_array($contract->getRawOriginal('status'), Contract::LIVE_STATUSES, true)) {
                return $result->singleValidationFailure('contract_locked', 'contract');
            }

            $catalogPlan = $this->catalogPlan($contract);

            if (($params['plan_code'] ?? null) !== null && $catalogPlan === null) {
                return $result->notFoundFailure('plan');
            }

            // Reject malformed dates, but let an explicit null clear the
            // field. A bare isset check would treat "" as absent, silently
            // keeping the column instead of rejecting the bad value.
            foreach (['billing_anchor_date', 'started_at', 'ended_at'] as $field) {
                if (! array_key_exists($field, $params) || $params[$field] === null) {
                    continue;
                }

                if (! Datetime::validFormat($params[$field], 'any')) {
                    return $result->singleValidationFailure('value_is_invalid', $field);
                }
            }

            if ($contract->active() && $this->lockedFieldChanged($contract, $catalogPlan)) {
                return $result->singleValidationFailure('contract_locked', 'contract');
            }

            $timezone = $contract->customer->applicableTimezone();

            // A window that already closed would never terminate — reject
            // it. A resend of the stored end date sets no new window, even
            // once it passed.
            if (($params['ended_at'] ?? null) !== null) {
                $endedAt = CarbonImmutable::parse((string) $params['ended_at'], 'UTC');

                if ($endedAt->setTimezone($timezone)->lessThanOrEqualTo(now())
                    && $contract->ended_at !== null
                    && $endedAt->getTimestamp() !== CarbonImmutable::instance($contract->ended_at)->getTimestamp()) {
                    return $result->singleValidationFailure('already_ended', 'ended_at');
                }
            }

            $planChanged = array_key_exists('plan_code', $params) && $catalogPlan?->id !== $contract->catalog_plan_id;

            // TODO(port): settings resolution (billing entity / payment
            // method / invoice custom section) — GraphQL-only inputs.

            return DB::transaction(function () use ($result, $contract, $catalogPlan, $planChanged): BaseResult {
                $params = $this->params;

                if (array_key_exists('name', $params)) {
                    $contract->name = $params['name'];
                }
                if (array_key_exists('ended_at', $params)) {
                    $contract->ended_at = $params['ended_at'] === null
                        ? null
                        : CarbonImmutable::parse((string) $params['ended_at'], 'UTC');
                }
                if (array_key_exists('purchase_order_number', $params)) {
                    $contract->purchase_order_number = $params['purchase_order_number'];
                }
                if (array_key_exists('consolidate_invoice', $params) && $params['consolidate_invoice'] !== null) {
                    $contract->consolidate_invoice = $params['consolidate_invoice'];
                }

                // An active contract only gets here with its locked fields
                // unchanged; writing them back would still version them on a
                // signed contract.
                if ($contract->pending()) {
                    if (($params['billing_time'] ?? null) !== null && $params['billing_time'] !== '') {
                        $contract->billing_time = $params['billing_time'];
                    }
                    if (array_key_exists('billing_anchor_date', $params)) {
                        $contract->billing_anchor_date = $params['billing_anchor_date'];
                    }
                    if (($params['started_at'] ?? null) !== null && $params['started_at'] !== '') {
                        $contract->started_at = CarbonImmutable::parse((string) $params['started_at'], 'UTC');
                    }
                    if (array_key_exists('plan_code', $params)) {
                        $contract->catalogPlan()->associate($catalogPlan);
                    }
                }

                $errors = $contract->validateAttributes();
                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $contract->save();

                // Replace the old plan's materialized cards.
                if ($planChanged) {
                    $contract->appliedRateCards->each(function ($card): void {
                        \App\Services\ContractRateCards\DestroyService::callBang(contractRateCard: $card);
                    });

                    if ($contract->catalogPlan !== null) {
                        MaterializeRateCardsService::callBang(contract: $contract->refresh());
                    }
                }

                $result->contract = $contract;

                return $result;
            });
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails: `locked_field_changed?` — forms resend every field, so an
     * unchanged locked value is not an edit.
     */
    private function lockedFieldChanged(Contract $contract, ?\App\Models\CatalogPlan $catalogPlan): bool
    {
        $changedFields = array_diff(array_keys($this->params), self::EDITABLE_WHILE_ACTIVE);

        foreach ($changedFields as $field) {
            if ($this->changes($field, $contract, $catalogPlan)) {
                return true;
            }
        }

        return false;
    }

    private function changes(string $field, Contract $contract, ?\App\Models\CatalogPlan $catalogPlan): bool
    {
        return match ($field) {
            'plan_code' => $catalogPlan?->id !== $contract->catalog_plan_id,
            'billing_time' => ($this->params['billing_time'] ?? null) !== null
                && (string) $this->params['billing_time'] !== (string) $contract->getRawOriginal('billing_time'),
            'started_at' => ($this->params['started_at'] ?? null) !== null
                && CarbonImmutable::parse((string) $this->params['started_at'], 'UTC')->getTimestamp()
                    !== CarbonImmutable::instance($contract->started_at)->getTimestamp(),
            'billing_anchor_date' => ! in_array(
                $this->params['billing_anchor_date'] !== null
                    ? CarbonImmutable::parse((string) $this->params['billing_anchor_date'], 'UTC')->startOfDay()
                    : null,
                [$contract->billing_anchor_date !== null ? CarbonImmutable::parse((string) $contract->billing_anchor_date, 'UTC')->startOfDay() : null, $contract->effectiveBillingAnchorDate()],
                false,
            ),
            // Any other field reaching here counts as changed (Rails: true).
            default => true,
        };
    }

    /**
     * Rails: `catalog_plan` — the contract keeps its plan even once
     * discarded, so a resend of the current code resolves to it instead of
     * looking up kept plans.
     */
    private function catalogPlan(Contract $contract): ?\App\Models\CatalogPlan
    {
        $planCode = $this->params['plan_code'] ?? null;

        if ($planCode === null || $planCode === '') {
            return null;
        }

        if ($contract->catalogPlan !== null && $planCode === $contract->catalogPlan->code) {
            return $contract->catalogPlan;
        }

        return $contract->organization->catalogPlans()->where('code', $planCode)->first();
    }
}
