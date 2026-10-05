<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions\Validators;

use Throwable;
use App\Models\Plan;
use App\Models\Charge;
use App\Models\Coupon;
use App\Enums\ChargeModel;
use App\Models\FixedCharge;
use App\Support\Utils\Datetime;
use App\Services\Validators\Currencies;
use App\Services\Utils\ChargeProperties;
use App\Services\Validators\DecimalAmount;
use App\Services\Validators\ExpirationDate;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\Charges\Validators\ChargeModelPropertiesValidator;

/**
 * Port of Rails' QuoteVersions::Validators::SubscriptionCreation::BusinessValidator
 * (app/services/quote_versions/validators/subscription_creation/business_validator.rb).
 *
 * The subscription-creation payload is the richest of the three order types:
 * it validates the quoted plans (with their charge / fixed-charge overrides),
 * the coupons and the wallet credits against both the deal currency and the
 * live catalog. Every check mirrors a constraint the execution flow — the
 * subscription, applied-coupon and wallet services — enforces later, so an
 * approved quote is never something execution would reject halfway.
 *
 * NOTE: payment terms are not validated yet (LAGO-1529 upstream), matching
 * Rails.
 */
class SubscriptionCreationValidator extends BaseOrderTypeValidator
{
    /** @var array<string, Plan>|null */
    protected ?array $knownPlansById = null;

    /** @var array<string, Coupon>|null */
    protected ?array $knownCouponsById = null;

    /** @var list<string>|null */
    protected ?array $knownPaymentMethodIds = null;

    /** @var list<string>|null */
    protected ?array $knownBillableMetricCodes = null;

    public function businessValid(): bool
    {
        $this->validateCurrency();
        $this->validateBillingEntity();
        $this->validatePlans();
        $this->validateCoupons();
        $this->validateWalletCredits();

        if ($this->hasErrors()) {
            return $this->reportErrors();
        }

        return true;
    }

    protected static function schemaClass(): string
    {
        return Schema::class;
    }

    // -- Plans -----------------------------------------------------------------

    protected function validatePlans(): void
    {
        foreach ($this->plans() as $index => $planItem) {
            $index = (int) $index;
            $plan = $this->knownPlan($planItem['id'] ?? null);

            if ($plan === null) {
                $this->addError($this->planField($index, 'id'), 'plan_not_found');

                continue;
            }

            $this->validatePlanCurrency($plan, $planItem, $index);
            $this->validateMinimumCommitment($plan, $planItem, $index);
            $this->validatePlanDates($planItem, $index);
            $this->validatePlanPaymentMethod($planItem, $index);
            $this->validateChargeOverrides($planItem, $plan, $index);
            $this->validateFixedChargeOverrides($planItem, $plan, $index);
        }
    }

    /**
     * Whatever currency the plan is priced in ends up billed, so it has to be
     * the deal's own. A catalog plan priced elsewhere is quoted by overriding
     * the currency, which Plans::OverrideService applies to the plan it
     * duplicates for the subscription.
     */
    protected function validatePlanCurrency(Plan $plan, array $planItem, int $index): void
    {
        $dealCurrency = $this->quoteVersion->currency;

        if ($dealCurrency === null || ! Currencies::valid($dealCurrency)) {
            return;
        }

        if ($this->effectivePlanCurrency($plan, $planItem) !== $dealCurrency) {
            $this->addError($this->planField($index, 'id'), 'currencies_does_not_match');
        }
    }

    protected function effectivePlanCurrency(Plan $plan, array $planItem): ?string
    {
        $override = $planItem['overrides']['amountCurrency'] ?? null;

        return ($override !== null && $override !== '') ? $override : $plan->amount_currency;
    }

    /**
     * Plans::OverrideService builds a fresh Commitment rather than duplicating
     * the plan's own, and Commitment rejects a nil amount, so the amount has
     * to reach it from one side or the other.
     */
    protected function validateMinimumCommitment(Plan $plan, array $planItem, int $index): void
    {
        if ($this->scope !== 'approve') {
            return;
        }

        $minimumCommitment = $planItem['overrides']['minimumCommitment'] ?? null;

        if ($minimumCommitment === null || ! is_array($minimumCommitment)) {
            return;
        }

        $amountCents = $minimumCommitment['amountCents']
            ?? $plan->minimumCommitment()->first()?->amount_cents;

        if ($amountCents === null) {
            $this->addError(
                $this->planField($index, 'overrides.minimumCommitment.amountCents'),
                'value_is_mandatory',
            );
        }
    }

    /**
     * Subscriptions::ValidateService requires the pair, compared as dates, to
     * be strictly increasing. An open-ended deal is legitimate — only a plan
     * stating both sides has a range to check. A plan stating no start date is
     * left alone: the deal simply starts on execution.
     */
    protected function validatePlanDates(array $planItem, int $index): void
    {
        $startDate = $planItem['payload']['startDate'] ?? null;
        $endDate = $planItem['payload']['endDate'] ?? null;

        $validStart = $this->validatePlanDate($startDate, $this->planField($index, 'payload.startDate'));
        $validEnd = $this->validatePlanDate($endDate, $this->planField($index, 'payload.endDate'));

        if (! $validStart || ! $validEnd) {
            return;
        }

        $this->validateFutureEndDate($endDate, $this->planField($index, 'payload.endDate'));

        $effectiveStart = $this->effectiveDate($startDate);
        $effectiveEnd = $this->effectiveDate($endDate);

        if ($effectiveStart === null || $effectiveEnd === null) {
            return;
        }

        if ($effectiveEnd <= $effectiveStart) {
            $this->addError($this->planField($index, 'payload.endDate'), 'invalid_date_range');
        }
    }

    /**
     * Subscriptions::ValidateService requires the ending date to be after
     * today; futureness is only guaranteed at approval time.
     */
    protected function validateFutureEndDate(mixed $value, string $field): void
    {
        if ($this->scope !== 'approve') {
            return;
        }

        $endDate = Datetime::parseIso8601($value)?->toDateString();

        if ($endDate === null) {
            return;
        }

        if ($endDate <= now()->toDateString()) {
            $this->addError($field, 'invalid_date');
        }
    }

    /** Same ISO 8601 check as Subscriptions::ValidateService. */
    protected function validatePlanDate(mixed $value, string $field): bool
    {
        if ($value === null) {
            return true;
        }

        if (Datetime::validFormat($value)) {
            return true;
        }

        $this->addError($field, 'invalid_date');

        return false;
    }

    /** Same parsing as Subscriptions::ValidateService. */
    protected function effectiveDate(mixed $value): ?string
    {
        return Datetime::parseIso8601($value)?->toDateString();
    }

    /**
     * Subscriptions::CreateService resolves the payment method by id and
     * organization only, so ownership is checked here: another customer's
     * method would otherwise be attached.
     */
    protected function validatePlanPaymentMethod(array $planItem, int $index): void
    {
        $paymentMethodId = $planItem['payload']['paymentMethodId'] ?? null;

        if ($paymentMethodId === null) {
            return;
        }

        if (in_array($paymentMethodId, $this->knownPaymentMethodIds(), true)) {
            return;
        }

        $this->addError($this->planField($index, 'payload.paymentMethodId'), 'payment_method_not_found');
    }

    // -- Charge overrides -------------------------------------------------------

    /**
     * Plans::OverrideService matches overrides by charge id and silently
     * ignores an id it cannot find, which would bill the catalog price
     * instead of the negotiated one. So the id the execution flow will use is
     * resolved here, from the payload snapshot, and must still exist on the
     * plan.
     */
    protected function validateChargeOverrides(array $planItem, Plan $plan, int $index): void
    {
        foreach ($this->chargeOverrides($planItem) as $chargeIndex => $chargeOverride) {
            $field = $this->planField($index, "overrides.charges.{$chargeIndex}.billableMetricCode");

            $snapshots = [];
            foreach ($this->snapshotCharges($planItem) as $snapshotIndex => $snapshot) {
                if (($snapshot['billableMetric']['code'] ?? null) === ($chargeOverride['billableMetricCode'] ?? null)) {
                    $snapshots[] = [$snapshot, $snapshotIndex];
                }
            }

            if ($snapshots === []) {
                $this->addError($field, 'charge_not_found');

                continue;
            }

            if (count($snapshots) > 1) {
                $this->addError($field, 'ambiguous_charge_override');

                continue;
            }

            [$snapshot, $snapshotIndex] = $snapshots[0];
            $charge = $plan->charges->firstWhere('id', $snapshot['id'] ?? null);

            if ($charge === null) {
                $this->addError($field, 'charge_not_found');

                continue;
            }

            $this->validateChargeModel($chargeOverride, $charge, $index, $chargeIndex);
            $this->validateSnapshotChargeModel(
                $snapshot,
                $charge,
                $this->planField($index, "payload.charges.{$snapshotIndex}.chargeModel"),
            );

            // The negotiated properties only mean something against the model
            // they were drafted for, and a model that moved is already
            // reported above.
            if ($this->modelChanged($chargeOverride['chargeModel'] ?? null, $charge)
                || $this->modelChanged($snapshot['chargeModel'] ?? null, $charge)) {
                continue;
            }

            $this->validateChargeProperties($chargeOverride, $charge, $index, $chargeIndex);
            $this->validateChargeMinAmount($chargeOverride, $charge, $index, $chargeIndex);
        }
    }

    /**
     * The snapshot pins the charge model the approver was looking at, and an
     * override omitting chargeModel would otherwise let the catalog drift
     * away from it unnoticed.
     */
    protected function validateSnapshotChargeModel(array $snapshot, Charge $charge, string $field): void
    {
        if (! $this->modelChanged($snapshot['chargeModel'] ?? null, $charge)) {
            return;
        }

        $this->addError($field, 'charge_model_changed');
    }

    /**
     * Charges::OverrideService cannot switch a charge model and ignores the
     * key, so properties negotiated for another model would land on the
     * catalog one.
     */
    protected function validateChargeModel(array $chargeOverride, Charge $charge, int $index, int $chargeIndex): void
    {
        if (! $this->modelChanged($chargeOverride['chargeModel'] ?? null, $charge)) {
            return;
        }

        $this->addError(
            $this->planField($index, "overrides.charges.{$chargeIndex}.chargeModel"),
            'cannot_override_charge_model',
        );
    }

    /** A quote that pinned no model follows whatever the catalog holds. */
    protected function modelChanged(mixed $quotedChargeModel, Charge|FixedCharge $chargeable): bool
    {
        if ($quotedChargeModel === null) {
            return false;
        }

        return $quotedChargeModel !== $this->chargeModelLabel($chargeable);
    }

    /** Rails reads charge_model as its name; the port maps the stored enum. */
    protected function chargeModelLabel(Charge|FixedCharge $chargeable): string
    {
        if ($chargeable instanceof FixedCharge) {
            return (string) $chargeable->charge_model;
        }

        return ChargeModel::tryFrom((int) $chargeable->charge_model)?->label()
            ?? (string) $chargeable->charge_model;
    }

    protected function validateChargeProperties(array $chargeOverride, Charge $charge, int $index, int $chargeIndex): void
    {
        $properties = ChargeProperties::underscoreKeys($chargeOverride['properties'] ?? null);

        if ($properties === null) {
            return;
        }

        $field = $this->planField($index, "overrides.charges.{$chargeIndex}.properties");

        $this->validateProperties($charge, $properties, $field);
    }

    /**
     * Charge#validate_min_amount_cents refuses a minimum on a charge billed in
     * advance, and Plans::OverrideService swallows that failure the same way.
     */
    protected function validateChargeMinAmount(array $chargeOverride, Charge $charge, int $index, int $chargeIndex): void
    {
        if (! $charge->pay_in_advance) {
            return;
        }

        if ((int) ($chargeOverride['minAmountCents'] ?? 0) <= 0) {
            return;
        }

        $this->addError(
            $this->planField($index, "overrides.charges.{$chargeIndex}.minAmountCents"),
            'not_compatible_with_pay_in_advance',
        );
    }

    // -- Fixed charge overrides -------------------------------------------------

    protected function validateFixedChargeOverrides(array $planItem, Plan $plan, int $index): void
    {
        foreach ($this->fixedChargeOverrides($planItem) as $fixedChargeIndex => $fixedChargeOverride) {
            $this->validateFixedChargeUnits($fixedChargeOverride, $index, $fixedChargeIndex);

            $field = $this->planField($index, "overrides.fixedCharges.{$fixedChargeIndex}.addOnCode");

            $snapshots = [];
            foreach ($this->snapshotFixedCharges($planItem) as $snapshotIndex => $snapshot) {
                if (($snapshot['addOn']['code'] ?? null) === ($fixedChargeOverride['addOnCode'] ?? null)) {
                    $snapshots[] = [$snapshot, $snapshotIndex];
                }
            }

            if ($snapshots === []) {
                $this->addError($field, 'fixed_charge_not_found');

                continue;
            }

            if (count($snapshots) > 1) {
                $this->addError($field, 'ambiguous_fixed_charge_override');

                continue;
            }

            [$snapshot, $snapshotIndex] = $snapshots[0];
            $fixedCharge = $plan->fixedCharges->firstWhere('id', $snapshot['id'] ?? null);

            if ($fixedCharge === null) {
                $this->addError($field, 'fixed_charge_not_found');

                continue;
            }

            $this->validateSnapshotFixedChargeModel(
                $snapshot,
                $fixedCharge,
                $this->planField($index, "payload.fixedCharges.{$snapshotIndex}.chargeModel"),
            );

            if ($this->modelChanged($snapshot['chargeModel'] ?? null, $fixedCharge)) {
                continue;
            }

            $this->validateFixedChargeProperties($fixedChargeOverride, $fixedCharge, $index, $fixedChargeIndex);
        }
    }

    /**
     * FixedCharges::OverrideService does refuse a model switch, but
     * Plans::OverrideService never checks its result and the override carries
     * no model of its own, so the snapshot is the only place the approved
     * model survives.
     */
    protected function validateSnapshotFixedChargeModel(array $snapshot, FixedCharge $fixedCharge, string $field): void
    {
        if (! $this->modelChanged($snapshot['chargeModel'] ?? null, $fixedCharge)) {
            return;
        }

        $this->addError($field, 'fixed_charge_model_changed');
    }

    /**
     * FixedCharges::OverrideService slices the properties down to the keys its
     * charge model knows before saving, and FixedCharge requires them to be
     * present, so an override drafted for another model filters down to
     * nothing and takes the fixed charge with it.
     */
    protected function validateFixedChargeProperties(array $fixedChargeOverride, FixedCharge $fixedCharge, int $index, int $fixedChargeIndex): void
    {
        try {
            $properties = ChargeProperties::underscoreKeys($fixedChargeOverride['properties'] ?? null);

            if ($properties === null) {
                return;
            }

            $field = $this->planField($index, "overrides.fixedCharges.{$fixedChargeIndex}.properties");

            $filtered = FilterPropertiesService::call(
                chargeable: $fixedCharge,
                properties: ($properties !== []) ? $properties : null,
            )->properties;

            if ($filtered === null || $filtered === []) {
                $this->addError($field, 'invalid_value');

                return;
            }

            $this->validateProperties($fixedCharge, $filtered, $field);
        } catch (Throwable) {
            $this->addError(
                $this->planField($index, "overrides.fixedCharges.{$fixedChargeIndex}.properties"),
                'invalid_value',
            );
        }
    }

    /**
     * These are the validators the charge / fixed-charge models themselves
     * run — Plans::OverrideService calls the two override services without
     * checking their results, so a payload the model rejects would otherwise
     * drop the charge silently.
     *
     * @param  mixed  $properties  underscored properties
     */
    protected function validateProperties(Charge|FixedCharge $chargeable, mixed $properties, string $field): void
    {
        $properties = is_array($properties) ? $properties : [];

        $codes = ChargeModelPropertiesValidator::validate(
            $this->chargeModelLabel($chargeable),
            $properties,
            $chargeable,
        );

        if ($codes === []) {
            return;
        }

        // Rails reads the validator's per-property messages; the shared port
        // flattens them, so every code lands on the field itself.
        foreach ($codes as $errorCode) {
            $this->addError($field, $errorCode);
        }
    }

    /**
     * units is forwarded verbatim to FixedCharges::OverrideService, and
     * FixedCharge validates it with `>= 0`.
     */
    protected function validateFixedChargeUnits(array $fixedChargeOverride, int $index, int $fixedChargeIndex): void
    {
        $units = $fixedChargeOverride['units'] ?? null;

        if ($units === null) {
            return;
        }

        if (DecimalAmount::validAmount($units)) {
            return;
        }

        $this->addError(
            $this->planField($index, "overrides.fixedCharges.{$fixedChargeIndex}.units"),
            'invalid_value',
        );
    }

    // -- Coupons ----------------------------------------------------------------

    protected function validateCoupons(): void
    {
        /** @var array<string, Coupon> $earlierCoupons */
        $earlierCoupons = [];

        foreach ($this->coupons() as $index => $couponItem) {
            $index = (int) $index;
            $coupon = $this->knownCoupon($couponItem['id'] ?? null);

            if ($coupon === null) {
                $this->addError($this->couponField($index, 'id'), 'coupon_not_found');

                continue;
            }

            $this->validateCouponCurrency($coupon, $couponItem, $index);
            $this->validateCouponFrequency($coupon, $couponItem, $index);
            $this->validateCouponPreconditions($coupon, $earlierCoupons, $index);

            if ($this->scope === 'approve') {
                $this->validateCouponSnapshot($coupon, $couponItem, $index);
            }

            $earlierCoupons[$coupon->id] = $coupon;
        }
    }

    /**
     * AppliedCoupons::CreateService refuses a non-reusable coupon the customer
     * already carries and a limited coupon overlapping one already applied.
     * The deal applies its coupons one by one, so the first application is
     * what makes a second one fail, halfway through execution.
     */
    protected function validateCouponPreconditions(Coupon $coupon, array $earlierCoupons, int $index): void
    {
        $isReusable = (bool) $coupon->reusable;

        if (! $isReusable && isset($earlierCoupons[$coupon->id])) {
            $this->addError($this->couponField($index, 'id'), 'coupon_is_not_reusable');
        }

        if (! $this->isLimited($coupon)) {
            return;
        }

        foreach ($earlierCoupons as $earlier) {
            if ($this->overlappingLimitations($coupon, $earlier)) {
                $this->addError($this->couponField($index, 'id'), 'plan_overlapping');

                return;
            }
        }
    }

    protected function isLimited(Coupon $coupon): bool
    {
        return (bool) $coupon->limited_plans || (bool) $coupon->limited_billable_metrics;
    }

    /**
     * The four comparisons AppliedCoupons::CreateService makes: plans against
     * plans, metrics against metrics, and each against the other through the
     * charges connecting them.
     */
    protected function overlappingLimitations(Coupon $coupon, Coupon $earlier): bool
    {
        $planIds = $this->targetIds($coupon, 'plan_id');
        $metricIds = $this->targetIds($coupon, 'billable_metric_id');
        $earlierPlanIds = $this->targetIds($earlier, 'plan_id');
        $earlierMetricIds = $this->targetIds($earlier, 'billable_metric_id');

        return count(array_intersect($planIds, $earlierPlanIds)) > 0
            || count(array_intersect($metricIds, $earlierMetricIds)) > 0
            || count(array_intersect($earlierPlanIds, $this->plansCharging($metricIds))) > 0
            || count(array_intersect($earlierMetricIds, $this->metricsChargedBy($planIds))) > 0;
    }

    /** @return list<string> */
    protected function targetIds(Coupon $coupon, string $attribute): array
    {
        $ids = [];

        foreach ($coupon->couponTargets as $target) {
            $id = $target->{$attribute};

            if ($id !== null) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /** @param  list<string>  $metricIds  @return list<string> */
    protected function plansCharging(array $metricIds): array
    {
        $plans = [];

        foreach ($metricIds as $metricId) {
            foreach ($this->plansByMetricId()[$metricId] ?? [] as $planId) {
                $plans[] = $planId;
            }
        }

        return $plans;
    }

    /** @param  list<string>  $planIds  @return list<string> */
    protected function metricsChargedBy(array $planIds): array
    {
        $metrics = [];

        foreach ($planIds as $planId) {
            foreach ($this->metricsByPlanId()[$planId] ?? [] as $metricId) {
                $metrics[] = $metricId;
            }
        }

        return $metrics;
    }

    /**
     * The charges connecting the two limitation kinds, resolved once for
     * every coupon the quote carries rather than once per pair compared.
     *
     * @return array<string, list<string>>
     */
    protected function plansByMetricId(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = Charge::query()
            ->whereIn('billable_metric_id', $this->quotedTargetIds('billable_metric_id'))
            ->get(['billable_metric_id', 'plan_id']);

        $cache = [];

        foreach ($rows as $row) {
            $cache[(string) $row->billable_metric_id][] = (string) $row->plan_id;
        }

        return $cache;
    }

    /** @return array<string, list<string>> */
    protected function metricsByPlanId(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = Charge::query()
            ->whereIn('plan_id', $this->quotedTargetIds('plan_id'))
            ->get(['plan_id', 'billable_metric_id']);

        $cache = [];

        foreach ($rows as $row) {
            $cache[(string) $row->plan_id][] = (string) $row->billable_metric_id;
        }

        return $cache;
    }

    /** @return list<string> */
    protected function quotedTargetIds(string $attribute): array
    {
        $ids = [];

        foreach ($this->knownCouponsById as $coupon) {
            foreach ($this->targetIds($coupon, $attribute) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The coupon is applied in whatever currency it carries, so it has to be
     * the deal's own.
     */
    protected function validateCouponCurrency(Coupon $coupon, array $couponItem, int $index): void
    {
        if ($coupon->coupon_type?->label() !== 'fixed_amount') {
            return;
        }

        $dealCurrency = $this->quoteVersion->currency;

        if ($dealCurrency === null || ! Currencies::valid($dealCurrency)) {
            return;
        }

        if ($this->effectiveCouponCurrency($coupon, $couponItem) !== $dealCurrency) {
            $this->addError($this->couponField($index, 'id'), 'currencies_does_not_match');
        }
    }

    protected function effectiveCouponCurrency(Coupon $coupon, array $couponItem): ?string
    {
        $override = $couponItem['overrides']['amountCurrency'] ?? null;

        return ($override !== null && $override !== '') ? $override : $coupon->amount_currency;
    }

    /**
     * The snapshot type is required at approve while the amount checks below
     * follow the live coupon, so a coupon retyped since the quote was drafted
     * must be flagged rather than silently followed.
     */
    protected function validateCouponSnapshot(Coupon $coupon, array $couponItem, int $index): void
    {
        if (($couponItem['payload']['type'] ?? null) !== $coupon->coupon_type?->label()) {
            $this->addError($this->couponField($index, 'payload.type'), 'coupon_type_does_not_match');

            return;
        }

        $isFixedAmount = $coupon->coupon_type?->label() === 'fixed_amount';
        $isPercentage = $coupon->coupon_type?->label() === 'percentage';

        if ($isFixedAmount && $this->quotedCouponValue($couponItem, 'amountCents') === null) {
            $this->addError($this->couponField($index, 'payload.amountCents'), 'value_is_mandatory');
        }

        if ($isPercentage && $this->quotedCouponValue($couponItem, 'percentageRate') === null) {
            $this->addError($this->couponField($index, 'payload.percentageRate'), 'value_is_mandatory');
        }
    }

    /**
     * AppliedCoupon requires a positive frequency_duration when recurring,
     * and AppliedCoupons::CreateService falls back to the live coupon's own
     * frequency and duration.
     */
    protected function validateCouponFrequency(Coupon $coupon, array $couponItem, int $index): void
    {
        if ($this->scope !== 'approve') {
            return;
        }

        $effectiveFrequency = $this->effectiveCouponValue(
            $couponItem,
            'frequency',
            $coupon->frequency?->label(),
        );

        if ($effectiveFrequency !== 'recurring') {
            return;
        }

        $effectiveDuration = $this->effectiveCouponValue($couponItem, 'frequencyDuration', $coupon->frequency_duration);

        if ($effectiveDuration !== null) {
            return;
        }

        $section = (($couponItem['overrides']['frequency'] ?? null) === 'recurring') ? 'overrides' : 'payload';

        $this->addError($this->couponField($index, "{$section}.frequencyDuration"), 'value_is_mandatory');
    }

    /** The way the execution services resolve a quoted value. */
    protected function quotedCouponValue(array $couponItem, string $field): mixed
    {
        return $couponItem['overrides'][$field] ?? $couponItem['payload'][$field] ?? null;
    }

    protected function effectiveCouponValue(array $couponItem, string $field, mixed $couponValue): mixed
    {
        return $this->quotedCouponValue($couponItem, $field) ?? $couponValue;
    }

    // -- Wallet credits ----------------------------------------------------------

    protected function validateWalletCredits(): void
    {
        foreach ($this->walletCredits() as $index => $walletCreditItem) {
            $index = (int) $index;
            $payload = $walletCreditItem['payload'] ?? [];

            $payload = is_array($payload) ? $payload : [];

            $this->validateWalletCreditAmounts($payload, $index);
            $this->validateWalletCreditCurrency($payload, $index);
            $this->validateWalletCreditAppliesTo($payload, $index);
            $this->validateExpiration($payload['expirationAt'] ?? null, $this->walletCreditField($index, 'payload.expirationAt'));
            $this->validateRecurringRules($payload, $index);
        }
    }

    /**
     * Outside api context Wallets::CreateService resolves metric limitations
     * by id, so a code that does not resolve means the wallet is created
     * without its limitation instead of failing.
     */
    protected function validateWalletCreditAppliesTo(array $payload, int $index): void
    {
        $codes = $payload['appliesTo']['billableMetricCodes'] ?? [];

        if (! is_array($codes) || $codes === []) {
            return;
        }

        $unknown = array_diff($codes, $this->knownBillableMetricCodes());

        if ($unknown !== []) {
            $this->addError(
                $this->walletCreditField($index, 'payload.appliesTo.billableMetricCodes'),
                'billable_metric_not_found',
            );
        }
    }

    /**
     * Unless the multi_currency flag is on, Wallets::CreateService forces the
     * customer currency onto the wallet.
     */
    protected function validateWalletCreditCurrency(array $payload, int $index): void
    {
        $currency = $payload['currency'] ?? null;

        if ($currency === null) {
            return;
        }

        $dealCurrency = $this->quoteVersion->currency;

        if ($dealCurrency === null || ! Currencies::valid($dealCurrency)) {
            return;
        }

        if ($currency !== $dealCurrency) {
            $this->addError($this->walletCreditField($index, 'payload.currency'), 'currencies_does_not_match');
        }
    }

    protected function validateExpiration(mixed $expirationAt, string $field): void
    {
        if ($this->scope !== 'approve') {
            return;
        }

        if (ExpirationDate::valid($expirationAt)) {
            return;
        }

        $this->addError($field, 'invalid_date');
    }

    protected function validateWalletCreditAmounts(array $payload, int $index): void
    {
        foreach (['paidCredits', 'grantedCredits'] as $key) {
            $value = $payload[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! DecimalAmount::validAmount($value)) {
                $this->addError($this->walletCreditField($index, "payload.{$key}"), 'invalid_value');
            }
        }

        $rateAmount = $payload['rateAmount'] ?? null;

        if ($rateAmount === null) {
            return;
        }

        if (! DecimalAmount::validPositiveAmount($rateAmount)) {
            $this->addError($this->walletCreditField($index, 'payload.rateAmount'), 'invalid_value');
        }
    }

    protected function validateRecurringRules(array $payload, int $walletCreditIndex): void
    {
        $rules = $payload['recurringTransactionRules'] ?? [];

        if (! is_array($rules)) {
            return;
        }

        foreach ($rules as $ruleIndex => $rule) {
            $ruleIndex = (int) $ruleIndex;
            $rule = is_array($rule) ? $rule : [];

            $this->validateRuleTrigger($rule, $walletCreditIndex, $ruleIndex);
            $this->validateRuleMethod($rule, $walletCreditIndex, $ruleIndex);
            $this->validateRuleGrantsTargetTopUp($rule, $walletCreditIndex, $ruleIndex);
            $this->validateRuleCredits($rule, $walletCreditIndex, $ruleIndex);
            $this->validateExpiration(
                $rule['expirationAt'] ?? null,
                $this->ruleField($walletCreditIndex, $ruleIndex, 'expirationAt'),
            );
        }
    }

    protected function validateRuleTrigger(array $rule, int $walletCreditIndex, int $ruleIndex): void
    {
        switch ($rule['trigger'] ?? null) {
            case 'interval':
                if (($rule['interval'] ?? null) === null) {
                    $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'interval'), 'value_is_mandatory');
                }

                break;

            case 'threshold':
                $threshold = $rule['thresholdCredits'] ?? null;

                if ($threshold === null) {
                    $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'thresholdCredits'), 'value_is_mandatory');
                } elseif (! $this->validDecimal($threshold)) {
                    $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'thresholdCredits'), 'invalid_value');
                }

                break;
        }
    }

    protected function validateRuleMethod(array $rule, int $walletCreditIndex, int $ruleIndex): void
    {
        if (($rule['method'] ?? null) !== 'target') {
            return;
        }

        $target = $rule['targetOngoingBalance'] ?? null;

        if ($target === null) {
            $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'targetOngoingBalance'), 'value_is_mandatory');

            return;
        }

        if (! $this->validDecimal($target)) {
            $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'targetOngoingBalance'), 'invalid_value');

            return;
        }

        if ($this->targetBelowThreshold($rule, $target)) {
            $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'targetOngoingBalance'), 'invalid_value');
        }
    }

    protected function targetBelowThreshold(array $rule, mixed $target): bool
    {
        if (($rule['trigger'] ?? null) !== 'threshold') {
            return false;
        }

        $thresholdCredits = $rule['thresholdCredits'] ?? null;

        if (! $this->validDecimal($thresholdCredits) || ! $this->validDecimal($target)) {
            return false;
        }

        return bccomp(DecimalAmount::canonical($target), DecimalAmount::canonical($thresholdCredits), 20) === -1;
    }

    /**
     * Upstream only accepts the flag on a target rule, see
     * Wallets::RecurringTransactionRules::ValidateService.
     */
    protected function validateRuleGrantsTargetTopUp(array $rule, int $walletCreditIndex, int $ruleIndex): void
    {
        if (($rule['grantsTargetTopUp'] ?? null) === null) {
            return;
        }

        if (($rule['method'] ?? null) === 'target') {
            return;
        }

        $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, 'grantsTargetTopUp'), 'invalid_value');
    }

    /**
     * NOTE: rule credits are deliberately sign-agnostic, mirroring upstream
     * Wallets::RecurringTransactionRules::ValidateService#valid_credits?.
     */
    protected function validateRuleCredits(array $rule, int $walletCreditIndex, int $ruleIndex): void
    {
        foreach (['paidCredits', 'grantedCredits'] as $key) {
            $value = $rule[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! $this->validDecimal($value)) {
                $this->addError($this->ruleField($walletCreditIndex, $ruleIndex, $key), 'invalid_value');
            }
        }
    }

    /** Rails: DecimalAmountService#valid_decimal? — string-only, finite. */
    protected function validDecimal(mixed $value): bool
    {
        return is_string($value) && DecimalAmount::canonical($value) !== null;
    }

    // -- Payload accessors --------------------------------------------------------

    /** @return list<array<string, mixed>> */
    protected function plans(): array
    {
        $items = $this->normalizedBillingItems()['plans'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function coupons(): array
    {
        $items = $this->normalizedBillingItems()['coupons'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function walletCredits(): array
    {
        $items = $this->normalizedBillingItems()['walletCredits'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function chargeOverrides(array $planItem): array
    {
        $items = $planItem['overrides']['charges'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function fixedChargeOverrides(array $planItem): array
    {
        $items = $planItem['overrides']['fixedCharges'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function snapshotCharges(array $planItem): array
    {
        $items = $planItem['payload']['charges'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return list<array<string, mixed>> */
    protected function snapshotFixedCharges(array $planItem): array
    {
        $items = $planItem['payload']['fixedCharges'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    protected function planField(int $index, string $suffix): string
    {
        return "billing_items.plans.{$index}.{$suffix}";
    }

    protected function couponField(int $index, string $suffix): string
    {
        return "billing_items.coupons.{$index}.{$suffix}";
    }

    protected function walletCreditField(int $index, string $suffix): string
    {
        return "billing_items.walletCredits.{$index}.{$suffix}";
    }

    protected function ruleField(int $walletCreditIndex, int $ruleIndex, string $suffix): string
    {
        return $this->walletCreditField($walletCreditIndex, "payload.recurringTransactionRules.{$ruleIndex}.{$suffix}");
    }

    // -- Catalog lookups ----------------------------------------------------------

    /** Rails: known_plans_by_id — with_discarded, eager-loading the overrides' targets. */
    protected function knownPlan(mixed $id): ?Plan
    {
        if ($this->knownPlansById === null) {
            $ids = array_values(array_filter(
                array_map(fn (array $item): mixed => $item['id'] ?? null, $this->plans()),
            ));

            $this->knownPlansById = $ids === []
                ? []
                : $this->quoteVersion->organization
                    ->plans()
                    ->withTrashed()
                    ->with(['charges', 'fixedCharges', 'minimumCommitment'])
                    ->whereIn('plans.id', array_map(strval(...), $ids))
                    ->get()
                    ->keyBy('id')
                    ->all();
        }

        return $this->knownPlansById[is_scalar($id) ? (string) $id : null] ?? null;
    }

    /** Rails: known_coupons_by_id — with_discarded, with the coupon targets. */
    protected function knownCoupon(mixed $id): ?Coupon
    {
        if ($this->knownCouponsById === null) {
            $ids = array_values(array_filter(
                array_map(fn (array $item): mixed => $item['id'] ?? null, $this->coupons()),
            ));

            $this->knownCouponsById = $ids === []
                ? []
                : Coupon::query()
                    ->withTrashed()
                    ->where('organization_id', $this->quoteVersion->organization_id)
                    ->with(['couponTargets'])
                    ->whereIn('coupons.id', array_map(strval(...), $ids))
                    ->get()
                    ->keyBy('id')
                    ->all();
        }

        return $this->knownCouponsById[is_scalar($id) ? (string) $id : null] ?? null;
    }

    /** @return list<string> */
    protected function knownPaymentMethodIds(): array
    {
        if ($this->knownPaymentMethodIds === null) {
            $ids = array_values(array_filter(array_map(
                fn (array $item): mixed => $item['payload']['paymentMethodId'] ?? null,
                $this->plans(),
            )));

            $this->knownPaymentMethodIds = $ids === []
                ? []
                : $this->quoteVersion->quote
                    ->customer
                    ->paymentMethods()
                    ->whereIn('payment_methods.id', array_map(strval(...), $ids))
                    ->pluck('payment_methods.id')
                    ->map(strval(...))
                    ->all();
        }

        return $this->knownPaymentMethodIds;
    }

    /** @return list<string> */
    protected function knownBillableMetricCodes(): array
    {
        if ($this->knownBillableMetricCodes === null) {
            $codes = [];

            foreach ($this->walletCredits() as $item) {
                $appliesTo = $item['payload']['appliesTo']['billableMetricCodes'] ?? [];

                if (is_array($appliesTo)) {
                    foreach ($appliesTo as $code) {
                        if (is_string($code) && $code !== '') {
                            $codes[] = $code;
                        }
                    }
                }
            }

            $this->knownBillableMetricCodes = $codes === []
                ? []
                : $this->quoteVersion->organization
                    ->billableMetrics()
                    ->whereIn('code', $codes)
                    ->pluck('code')
                    ->all();
        }

        return $this->knownBillableMetricCodes;
    }
}
