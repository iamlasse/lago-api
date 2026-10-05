<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Models\Commitment;
use App\Enums\PlanInterval;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\Charges\GenerateCodeService;
use App\Services\Charges\CreateService as ChargesCreateService;
use App\Services\FixedCharges\CreateService as FixedChargesCreateService;
use App\Services\Commitments\ApplyTaxesService as CommitmentsApplyTaxesService;
use App\Services\FixedCharges\GenerateCodeService as FixedChargesGenerateCodeService;

use function array_key_exists;

/**
 * Port of Rails' Plans::CreateService (app/services/plans/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan metadata (Metadata::ItemMetadata) — the `metadata` arg
 *   is accepted and ignored.
 * - TODO(port): AppliedPricingUnits — premium applied pricing params are
 *   accepted and ignored.
 * - TODO(port): SegmentTrackJob + activity log middleware.
 */
class CreateService extends BaseService
{
    /**
     * Plan-level pricing and chargeables belong to the legacy engine.
     *
     * @return list<string>
     */
    public const LEGACY_PRICING_FIELDS = ['interval', 'amount_cents', 'pay_in_advance', 'charges', 'fixed_charges'];

    public function __construct(
        private readonly array $args,
        private readonly bool $sendWebhook = true,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('plan');
        $args = $this->args;

        $organization = \App\Models\Organization::query()->find($args['organization_id'] ?? null);

        if ($organization === null) {
            // Rails: Plan#save! fails the belongs_to :organization presence
            // validation (RecordInvalid -> record_validation_failure!).
            return $result->validationFailure(['organization' => ['value_is_mandatory']]);
        }

        if ($this->productCatalogEnabled($organization)) {
            $legacyField = $this->findLegacyField($args, valuePresenceOnly: true);

            if ($legacyField !== null) {
                return $result->singleValidationFailure('legacy_billing_disabled', $legacyField);
            }

            // Blank no-ops (pay_in_advance: false) are tolerated but never
            // persisted.
            foreach (self::LEGACY_PRICING_FIELDS as $field) {
                unset($args[$field]);
            }
        }

        $intervalOption = PlanInterval::fromOption($args['interval'] ?? null);
        $intervalInput = $args['interval'] ?? null;

        $plan = new Plan([
            'organization_id' => $args['organization_id'] ?? null,
            'name' => $args['name'] ?? null,
            'invoice_display_name' => $args['invoice_display_name'] ?? null,
            'code' => $args['code'] ?? null,
            'description' => $args['description'] ?? null,
            'interval' => $intervalOption,
            'pay_in_advance' => $args['pay_in_advance'] ?? null,
            'amount_cents' => $args['amount_cents'] ?? null,
            'amount_currency' => $args['amount_currency'] ?? null,
            'trial_period' => $args['trial_period'] ?? null,
            'bill_charges_monthly' => $this->billChargesMonthly($args),
            'bill_fixed_charges_monthly' => $this->billFixedChargesMonthly($args),
        ]);

        if ($intervalInput !== null && $intervalOption === null) {
            // Rails: `interval&.to_sym` on an unknown value fails the enum's
            // inclusion validation at save time.
            $plan->interval = -1;
        }

        $chargeablesValidationResult = ChargeablesValidationService::call(
            organization: $organization,
            charges: $this->present($args['charges'] ?? null),
            fixedCharges: $this->present($args['fixed_charges'] ?? null),
        );

        if ($chargeablesValidationResult->failure()) {
            return $chargeablesValidationResult;
        }

        try {
            DB::transaction(function () use ($plan, $args): void {
                $this->savePlan($plan);

                // TODO(port): create_metadata — Metadata::ItemMetadata model
                // does not exist yet; the metadata arg is ignored.

                if (array_key_exists('tax_codes', $args) && $args['tax_codes'] !== null) {
                    ApplyTaxesService::call(
                        plan: $plan,
                        taxCodes: (array) $args['tax_codes'],
                    )->raiseIfError();
                }

                // UsageThresholds::UpdateService — WIRED (usage-monitoring
                // slice; progressive billing is premium-gated).
                if ($this->present($args['usage_thresholds'] ?? null) !== []
                    && $plan->organization->progressiveBillingEnabled()) {
                    \App\Services\UsageThresholds\UpdateService::callBang(
                        model: $plan,
                        usageThresholdsParams: $args['usage_thresholds'],
                        partial: false,
                    );
                }

                if ($this->present($args['charges'] ?? null) !== []) {
                    foreach ($args['charges'] as $chargeParams) {
                        ChargesCreateService::call(
                            plan: $plan,
                            params: $this->chargeParamsWithCode($plan, (array) $chargeParams),
                        )->raiseIfError();
                    }
                }

                if ($this->present($args['fixed_charges'] ?? null) !== []) {
                    foreach ($args['fixed_charges'] as $fixedChargeArgs) {
                        FixedChargesCreateService::call(
                            plan: $plan,
                            params: $this->fixedChargeParamsWithCode($plan, (array) $fixedChargeArgs),
                        )->raiseIfError();
                    }
                }

                // Rails: `args[:minimum_commitment].present?` — an EMPTY hash
                // is blank and skips the branch entirely. The front's
                // serializeMinimumCommitment sends `{}` when the user added
                // no commitment, so a presence check (not a !== null check)
                // is load-bearing: otherwise every commitment-less plan
                // create crashes on the commitment insert.
                $minimumCommitmentInput = $args['minimum_commitment'] ?? null;

                if (is_array($minimumCommitmentInput) && $minimumCommitmentInput !== [] && $this->premium()) {
                    $minimumCommitment = $this->createCommitment($plan, $minimumCommitmentInput);

                    if ((array_key_exists('tax_codes', $minimumCommitmentInput)
                        && $minimumCommitmentInput['tax_codes'] !== null
                        && $minimumCommitmentInput['tax_codes'] !== [])) {
                        CommitmentsApplyTaxesService::call(
                            commitment: $minimumCommitment,
                            taxCodes: (array) $minimumCommitmentInput['tax_codes'],
                        )->raiseIfError();
                    }
                }
            });

            // TODO(port): SendWebhookJob.perform_after_commit("plan.created", plan)
            // — webhook emission hook point.

            $result->plan = $plan;

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findLegacyField(array $args, bool $valuePresenceOnly): ?string
    {
        foreach (self::LEGACY_PRICING_FIELDS as $field) {
            $value = $args[$field] ?? null;

            $present = match ($valuePresenceOnly) {
                true => $value !== null && $value !== [] && $value !== false && $value !== '',
                false => array_key_exists($field, $args),
            };

            if ($present) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Rails: `create_commitment`.
     *
     * @param  array<string, mixed>  $args
     */
    private function createCommitment(Plan $plan, array $args): Commitment
    {
        // Rails: Commitment validates `amount_cents` numericality > 0
        // (allow_nil: false) — the front always submits the block, even when
        // the user added no commitment, and the RecordInvalid maps to
        // record_validation_failure instead of a raw SQL not-null error.
        $amountCents = $args['amount_cents'] ?? null;

        if ($amountCents === null || (int) $amountCents <= 0) {
            static::makeResult('plan')
                ->recordValidationFailure(['amount_cents' => ['invalid_amount']])
                ->raiseIfError();
        }

        $commitment = new Commitment([
            'organization_id' => $plan->organization_id,
            'plan_id' => $plan->id,
            'commitment_type' => Commitment::MINIMUM_COMMITMENT,
            'invoice_display_name' => $args['invoice_display_name'] ?? null,
            'amount_cents' => $args['amount_cents'] ?? null,
        ]);

        $commitment->save();

        return $commitment;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function billChargesMonthly(array $args): ?bool
    {
        if (! $this->chargesBillableMonthly($args)) {
            return null;
        }

        return $args['bill_charges_monthly'] ?? false;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function billFixedChargesMonthly(array $args): ?bool
    {
        if (! $this->chargesBillableMonthly($args)) {
            return null;
        }

        return $args['bill_fixed_charges_monthly'] ?? false;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function chargesBillableMonthly(array $args): bool
    {
        $interval = PlanInterval::fromOption($args['interval'] ?? null);

        return in_array($interval, [PlanInterval::Yearly->value, PlanInterval::Semiannual->value], true);
    }

    /**
     * @param  array<string, mixed>  $chargeParams
     * @return array<string, mixed>
     */
    private function chargeParamsWithCode(Plan $plan, array $chargeParams): array
    {
        if (($chargeParams['code'] ?? null) !== null && $chargeParams['code'] !== '') {
            return $chargeParams;
        }

        $billableMetric = \App\Models\BillableMetric::query()
            ->where('organization_id', $plan->organization_id)
            ->where('id', $chargeParams['billable_metric_id'] ?? null)
            ->first();

        if ($billableMetric === null) {
            return $chargeParams;
        }

        $chargeParams['code'] = GenerateCodeService::call(
            plan: $plan,
            billableMetric: $billableMetric,
        )->code;

        return $chargeParams;
    }

    /**
     * @param  array<string, mixed>  $fixedChargeParams
     * @return array<string, mixed>
     */
    private function fixedChargeParamsWithCode(Plan $plan, array $fixedChargeParams): array
    {
        if (($fixedChargeParams['code'] ?? null) !== null && $fixedChargeParams['code'] !== '') {
            return $fixedChargeParams;
        }

        $addOn = \App\Models\AddOn::query()
            ->where('organization_id', $plan->organization_id)
            ->where('id', $fixedChargeParams['add_on_id'] ?? null)
            ->first();

        if ($addOn === null) {
            return $fixedChargeParams;
        }

        $fixedChargeParams['code'] = FixedChargesGenerateCodeService::call(
            plan: $plan,
            addOn: $addOn,
        )->code;

        return $fixedChargeParams;
    }

    /**
     * Rails: `organization.product_catalog_enabled?` — feature flag, not
     * license-gated.
     */
    private function productCatalogEnabled(\App\Models\Organization $organization): bool
    {
        return in_array('product_catalog', (array) ($organization->feature_flags ?? []), true);
    }

    private function savePlan(Plan $plan): void
    {
        $errors = $plan->validateAttributes();

        if ($errors !== []) {
            static::makeResult()->recordValidationFailure($errors)->raiseIfError();
        }

        $plan->save();
    }

    private function present(mixed $value): array
    {
        return (is_array($value) && $value !== []) ? $value : [];
    }
}
