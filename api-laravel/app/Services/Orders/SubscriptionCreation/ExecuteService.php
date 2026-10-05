<?php

declare(strict_types=1);

namespace App\Services\Orders\SubscriptionCreation;

use App\Models\Plan;
use App\Models\Order;
use App\Models\Charge;
use App\Models\Coupon;
use App\Enums\ChargeModel;
use Illuminate\Support\Str;
use App\Models\BillableMetric;
use App\Support\Utils\Datetime;
use App\Services\Credits\CouponLock;
use App\Services\Orders\BaseExecuteService;
use App\Services\Wallets\CreateService as WalletCreateService;
use App\Services\Subscriptions\CreateService as SubscriptionCreateService;
use App\Services\AppliedCoupons\CreateService as AppliedCouponCreateService;

/**
 * Port of Rails' Orders::SubscriptionCreation::ExecuteService
 * (app/services/orders/subscription_creation/execute_service.rb) — executes
 * a subscription-creation order: creates the subscriptions (with plan
 * overrides), applies the coupons and opens the wallets.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Customers::LockService — the advisory coupon-scope lock is
 *   taken via the ported CouponLock (same lock the AppliedCoupons service
 *   takes), matching the Rails ordering.
 * - TODO(port): recurring_transaction_rules creation —
 *   RecurringTransactionRule is a later slice; the params are forwarded but
 *   Wallets::CreateService defers the first grant.
 */
class ExecuteService extends BaseExecuteService
{
    protected const CALENDAR_DATE = '/^\d{4}-\d{2}-\d{2}\z/';

    protected ?array $couponsById = null;

    // -- Key helpers ------------------------------------------------------------------

    /** Rails: Utils::ChargeProperties.underscore_keys. */
    public static function underscoreKeys(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $sub) {
                $out[is_string($key) ? self::underscoreKey($key) : $key] = self::underscoreKeys($sub);
            }

            return $out;
        }

        return $value;
    }

    public static function underscoreKey(string $key): string
    {
        return (string) Str::of($key)->snake();
    }

    protected function createRecords(): array
    {
        $order = $this->order;
        assert($order !== null);

        [$subscriptions, $appliedCoupons, $wallets] = $this->withCouponLock(function () use ($order): array {
            $subscriptions = $this->createSubscriptions($order);
            $appliedCoupons = $this->applyCoupons($order);
            $wallets = $this->createWallets($order);

            return [$subscriptions, $appliedCoupons, $wallets];
        });

        return [
            'subscription_ids' => array_map(fn ($s) => $s->id, $subscriptions),
            'applied_coupon_ids' => array_map(fn ($ac) => $ac->id, $appliedCoupons),
            'wallet_ids' => array_map(fn ($w) => $w->id, $wallets),
        ];
    }

    /**
     * Rails: `with_coupon_lock` — AppliedCoupons::CreateService takes this
     * lock and then writes the customer row, while
     * Subscriptions::CreateService locks the row first. Taking it up front
     * keeps one ordering for the whole deal.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withCouponLock(callable $callback): mixed
    {
        if ($this->couponItems() === []) {
            return $callback();
        }

        $order = $this->order;
        assert($order !== null);

        return CouponLock::withLock($order->customer, 'coupon', $callback);
    }

    /**
     * NOTE: an external id matching a live subscription of this customer
     * makes Subscriptions::CreateService reuse, upgrade or downgrade it
     * instead of creating one.
     *
     * @return list<\App\Models\Subscription>
     */
    protected function createSubscriptions(Order $order): array
    {
        $subscriptions = [];

        foreach ($this->planItems() as $item) {
            $plan = $this->findPlan($order, $item['id'] ?? null);

            $result = SubscriptionCreateService::call(
                customer: $order->customer,
                plan: $plan,
                params: $this->subscriptionParams($order, $item, $plan),
            )->raiseIfError();

            $subscriptions[] = $result->subscription;
        }

        return $subscriptions;
    }

    /** @return array<string, mixed> */
    protected function subscriptionParams(Order $order, array $item, Plan $plan): array
    {
        $payload = (array) ($item['payload'] ?? []);

        $params = [
            // Both quoted ids are stable, so a retry after a failed execution
            // reuses the same external id. NOTE: localId is optional on plan
            // items, so an item carrying neither mints a new external id on
            // every attempt.
            'external_id' => ($payload['subscriptionExternalId'] ?? null)
                ?: ($item['localId'] ?? null)
                ?: (string) Str::uuid(),
            // Mandatory under the api source, where the customer is normally
            // identified by it rather than passed. Inert otherwise, but the
            // customer here is always the order's own.
            'external_customer_id' => $order->customer->external_id,
            'name' => $payload['subscriptionName'] ?? null,
            'billing_time' => $payload['billingTime'] ?? null,
            'subscription_at' => $this->subscriptionDatetime($order, $payload['startDate'] ?? null),
            'ending_at' => $this->subscriptionDatetime($order, $payload['endDate'] ?? null),
            'payment_method' => $this->paymentMethodParams($payload),
            'billing_entity_id' => $this->quotedBillingEntityId($order),
        ];

        $planOverrides = $this->planOverrides($order, $item, $plan);
        if ($planOverrides !== []) {
            $params['plan_overrides'] = $planOverrides;
        }

        $usageThresholds = $this->usageThresholds($item);
        if ($usageThresholds !== []) {
            $params['usage_thresholds'] = $usageThresholds;
        }

        return array_filter($params, fn ($value) => $value !== null);
    }

    /**
     * The raw column, not the applicable one: a deal that named no entity
     * leaves the subscription and the wallets inheriting the customer's at
     * billing time, which is what NULL means here.
     */
    protected function quotedBillingEntityId(Order $order): ?string
    {
        return $order->quoteVersion()?->billing_entity_id;
    }

    /**
     * The payload may carry a bare date. A date reaches a datetime attribute
     * as midnight UTC, which is the previous day for a customer west of UTC:
     * Subscriptions::CreateService would then take its past subscription path
     * and anniversary date. A calendar date means that day for the customer,
     * so it is read in their timezone.
     */
    protected function subscriptionDatetime(Order $order, mixed $payloadValue): mixed
    {
        $value = ($payloadValue ?? '') === '' ? null : $payloadValue;

        $date = $this->calendarDate($value);

        if ($date === null) {
            return $value;
        }

        return \Carbon\CarbonImmutable::instance($date)->setTimezone($order->customer->applicableTimezone());
    }

    /** A string that only looks like a date is left alone for Subscriptions::ValidateService to reject. */
    protected function calendarDate(mixed $value): ?\Carbon\CarbonInterface
    {
        if (! is_string($value) || preg_match(static::CALENDAR_DATE, $value) !== 1) {
            return null;
        }

        return Datetime::parseIso8601($value);
    }

    /**
     * PaymentMethods::ValidateService refuses an id without its type.
     *
     * @return array<string, mixed>|null
     */
    protected function paymentMethodParams(array $payload): ?array
    {
        $paymentMethodId = $payload['paymentMethodId'] ?? null;

        if ($paymentMethodId === null) {
            return null;
        }

        return ['payment_method_type' => 'provider', 'payment_method_id' => $paymentMethodId];
    }

    /** @return array<string, mixed> */
    protected function planOverrides(Order $order, array $item, Plan $plan): array
    {
        $overrides = (array) ($item['overrides'] ?? []);

        $overridesOut = [
            'amount_cents' => $overrides['amountCents'] ?? null,
            'amount_currency' => $overrides['amountCurrency'] ?? null,
            'invoice_display_name' => $overrides['invoiceDisplayName'] ?? null,
            'name' => $overrides['name'] ?? null,
            'description' => $overrides['description'] ?? null,
            'trial_period' => $overrides['trialPeriod'] ?? null,
            'minimum_commitment' => $this->minimumCommitment($overrides, $plan),
            'charges' => $this->chargeOverrides($order, $item, $plan),
            'fixed_charges' => $this->fixedChargeOverrides($order, $item, $plan),
        ];

        // Rails: .compact — nil values are dropped, empty arrays via presence.
        $overridesOut = array_filter($overridesOut, fn ($value) => $value !== null);

        if (($overridesOut['charges'] ?? null) === []) {
            unset($overridesOut['charges']);
        }
        if (($overridesOut['fixed_charges'] ?? null) === []) {
            unset($overridesOut['fixed_charges']);
        }

        return $overridesOut;
    }

    /**
     * Plans::OverrideService builds a fresh Commitment rather than
     * duplicating the plan's own, and Commitment rejects a nil amount, so an
     * override renaming the commitment without repricing it only reaches a
     * valid record if the plan's own amount is forwarded here.
     *
     * @return array<string, mixed>|null
     */
    protected function minimumCommitment(array $overrides, Plan $plan): ?array
    {
        $commitment = $overrides['minimumCommitment'] ?? null;

        if (! is_array($commitment) || $commitment === []) {
            return null;
        }

        $out = [
            'amount_cents' => ($commitment['amountCents'] ?? null)
                ?? $plan->minimumCommitment()->first()?->amount_cents,
            'invoice_display_name' => $commitment['invoiceDisplayName'] ?? null,
        ];

        $out = array_filter($out, fn ($value) => $value !== null);

        return $out === [] ? null : $out;
    }

    /**
     * chargeModel is not forwarded: Charges::OverrideService cannot switch
     * models and ignores it.
     *
     * @return list<array<string, mixed>>
     */
    protected function chargeOverrides(Order $order, array $item, Plan $plan): array
    {
        $out = [];

        foreach ((array) ($item['overrides']['charges'] ?? []) as $override) {
            $override = (array) $override;

            $entry = [
                'id' => $this->chargeId($order, $item, $plan, $override['billableMetricCode'] ?? null),
            ];

            if (array_key_exists('properties', $override)) {
                $entry['properties'] = self::underscoreKeys($override['properties']);
            }
            if (array_key_exists('minAmountCents', $override)) {
                $entry['min_amount_cents'] = $override['minAmountCents'];
            }
            if (array_key_exists('invoiceDisplayName', $override)) {
                $entry['invoice_display_name'] = $override['invoiceDisplayName'];
            }

            $out[] = array_filter($entry, fn ($value) => $value !== null);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected function fixedChargeOverrides(Order $order, array $item, Plan $plan): array
    {
        $out = [];

        foreach ((array) ($item['overrides']['fixedCharges'] ?? []) as $override) {
            $override = (array) $override;

            $entry = [
                'id' => $this->fixedChargeId($order, $item, $plan, $override['addOnCode'] ?? null),
            ];

            if (array_key_exists('units', $override)) {
                $entry['units'] = $override['units'];
            }
            if (array_key_exists('properties', $override)) {
                $entry['properties'] = self::underscoreKeys($override['properties']);
            }
            if (array_key_exists('invoiceDisplayName', $override)) {
                $entry['invoice_display_name'] = $override['invoiceDisplayName'];
            }

            $out[] = array_filter($entry, fn ($value) => $value !== null);
        }

        return $out;
    }

    /**
     * Plans::OverrideService matches by id and silently ignores one it cannot
     * find, which would bill the catalog price. The charge must still be on
     * the plan, whatever happened to the catalog since the quote was approved.
     */
    protected function chargeId(Order $order, array $item, Plan $plan, mixed $metricCode): string
    {
        $snapshot = null;

        foreach ((array) ($item['payload']['charges'] ?? []) as $charge) {
            $charge = (array) $charge;

            if ((($charge['billableMetric']['code'] ?? null)) === $metricCode) {
                $snapshot = $charge;
                break;
            }
        }

        $chargeId = $snapshot['id'] ?? null;

        $charge = $chargeId === null
            ? null
            : $plan->charges()->whereKey($chargeId)->first();

        $result = static::makeResult('order');

        if ($charge === null) {
            $result->notFoundFailure('charge')->raiseIfError();
        }

        assert($charge instanceof Charge);

        if ($this->chargeModelChanged($snapshot, $charge)) {
            $result->singleValidationFailure('charge_model_changed', 'charge_model')->raiseIfError();
        }

        return $charge->id;
    }

    protected function fixedChargeId(Order $order, array $item, Plan $plan, mixed $addOnCode): string
    {
        $snapshot = null;

        foreach ((array) ($item['payload']['fixedCharges'] ?? []) as $fixedCharge) {
            $fixedCharge = (array) $fixedCharge;

            if ((($fixedCharge['addOn']['code'] ?? null)) === $addOnCode) {
                $snapshot = $fixedCharge;
                break;
            }
        }

        $fixedChargeId = $snapshot['id'] ?? null;

        $fixedCharge = $fixedChargeId === null
            ? null
            : $plan->fixedCharges()->whereKey($fixedChargeId)->first();

        $result = static::makeResult('order');

        if ($fixedCharge === null) {
            $result->notFoundFailure('fixed_charge')->raiseIfError();
        }

        if ($this->fixedChargeModelChanged($snapshot, $fixedCharge)) {
            $result->singleValidationFailure('fixed_charge_model_changed', 'fixed_charge_model')->raiseIfError();
        }

        return $fixedCharge->id;
    }

    /**
     * The negotiated properties were approved against the model the snapshot
     * pinned. A catalog model change since then makes them invalid for the
     * charge they now land on, and the override services fail on that inside
     * Plans::OverrideService, which ignores their result: the charge would be
     * dropped and the subscription would bill nothing for it.
     */
    protected function chargeModelChanged(?array $snapshot, Charge $charge): bool
    {
        $chargeModel = $snapshot['chargeModel'] ?? null;

        if ($chargeModel === null) {
            return false;
        }

        $options = ChargeModel::options();
        $index = (int) $charge->charge_model;

        return $chargeModel !== ($options[$index] ?? null);
    }

    /** fixed_charges.charge_model is a native PG enum (the string itself). */
    protected function fixedChargeModelChanged(?array $snapshot, mixed $fixedCharge): bool
    {
        $chargeModel = $snapshot['chargeModel'] ?? null;

        if ($chargeModel === null) {
            return false;
        }

        return $chargeModel !== (is_string($fixedCharge->charge_model) ? $fixedCharge->charge_model : null);
    }

    /**
     * Thresholds ride on the subscription, not on the overridden plan:
     * plan_overrides .usage_thresholds is the deprecated path and combining
     * both is refused upstream.
     *
     * @return list<array<string, mixed>>
     */
    protected function usageThresholds(array $item): array
    {
        $out = [];

        foreach ((array) ($item['overrides']['usageThresholds'] ?? []) as $threshold) {
            $threshold = (array) $threshold;

            $entry = [];
            if (array_key_exists('amountCents', $threshold)) {
                $entry['amount_cents'] = $threshold['amountCents'];
            }
            if (array_key_exists('recurring', $threshold)) {
                $entry['recurring'] = $threshold['recurring'];
            }
            if (array_key_exists('thresholdDisplayName', $threshold)) {
                $entry['threshold_display_name'] = $threshold['thresholdDisplayName'];
            }

            $out[] = array_filter($entry, fn ($value) => $value !== null);
        }

        return $out;
    }

    /** @return list<\App\Models\AppliedCoupon> */
    protected function applyCoupons(Order $order): array
    {
        $appliedCoupons = [];

        foreach ($this->couponItems() as $item) {
            $coupon = $this->couponsById($order)[$item['id'] ?? null] ?? null;

            if ($coupon === null) {
                static::makeResult('order')->notFoundFailure('coupon')->raiseIfError();
            }

            $appliedCoupons[] = AppliedCouponCreateService::call(
                customer: $order->customer,
                coupon: $coupon,
                params: $this->couponParams($item),
            )->raiseIfError()->applied_coupon;
        }

        return $appliedCoupons;
    }

    /**
     * Only the override states a currency: the payload's own is the catalog
     * snapshot, and AppliedCoupons::CreateService already falls back to the
     * live coupon's when none is given.
     *
     * @return array<string, mixed>
     */
    protected function couponParams(array $item): array
    {
        $params = [
            'amount_cents' => $this->effectiveValue($item, 'amountCents'),
            'amount_currency' => $item['overrides']['amountCurrency'] ?? null,
            'percentage_rate' => $this->effectiveValue($item, 'percentageRate'),
            'frequency' => $this->effectiveValue($item, 'frequency'),
            'frequency_duration' => $this->effectiveValue($item, 'frequencyDuration'),
        ];

        return array_filter($params, fn ($value) => $value !== null);
    }

    /** @return list<mixed> */
    protected function createWallets(Order $order): array
    {
        $wallets = [];

        foreach ($this->walletCreditItems() as $item) {
            $wallets[] = WalletCreateService::callBang(
                params: $this->walletParams($order, $item),
            )->wallet;
        }

        return $wallets;
    }

    /** @return array<string, mixed> */
    protected function walletParams(Order $order, array $item): array
    {
        $payload = (array) ($item['payload'] ?? []);

        $params = [
            'organization_id' => $order->organization_id,
            'customer' => $order->customer,
            'currency' => ($payload['currency'] ?? null) ?? $order->currency(),
            'billing_entity_id' => $this->quotedBillingEntityId($order),
            'name' => $payload['name'] ?? null,
            'rate_amount' => $payload['rateAmount'] ?? null,
            'paid_credits' => $payload['paidCredits'] ?? null,
            'granted_credits' => $payload['grantedCredits'] ?? null,
            'expiration_at' => $payload['expirationAt'] ?? null,
            'purchase_order_number' => $payload['purchaseOrderNumber'] ?? null,
            'invoice_requires_successful_payment' => $payload['invoiceRequiresSuccessfulPayment'] ?? null,
            'applies_to' => $this->appliesTo($order, $payload),
            'recurring_transaction_rules' => $this->recurringTransactionRules($payload),
        ];

        return array_filter($params, fn ($value) => $value !== null);
    }

    /** @return array<string, mixed>|null */
    protected function appliesTo(Order $order, array $payload): ?array
    {
        $appliesTo = $payload['appliesTo'] ?? null;

        if (! is_array($appliesTo) || $appliesTo === []) {
            return null;
        }

        $feeTypes = ($appliesTo['feeTypes'] ?? null) ?: null;
        $billableMetricCodes = ($appliesTo['billableMetricCodes'] ?? null) ?: null;

        $out = [
            'fee_types' => $feeTypes,
            // Wallets::CreateService reads the codes under the api source and
            // the ids otherwise, so the limitation must be stated both ways
            // for the execution to be transport independent.
            'billable_metric_codes' => $billableMetricCodes,
            'billable_metric_ids' => $this->billableMetricIds($order, $billableMetricCodes),
        ];

        $out = array_filter($out, fn ($value) => $value !== null);

        return $out === [] ? null : $out;
    }

    /**
     * Outside api context Wallets::CreateService resolves the limitation by
     * id, so a code that no longer exists would create a wallet without its
     * limitation.
     *
     * @param  list<string>|null  $codes
     * @return list<string>|null
     */
    protected function billableMetricIds(Order $order, ?array $codes): ?array
    {
        if ($codes === null || $codes === []) {
            return null;
        }

        $ids = BillableMetric::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('code', $codes)
            ->pluck('id')
            ->all();

        if (count($ids) !== count(array_unique($codes))) {
            static::makeResult('order')->notFoundFailure('billable_metric')->raiseIfError();
        }

        return $ids;
    }

    /** @return list<array<string, mixed>>|null */
    protected function recurringTransactionRules(array $payload): ?array
    {
        $keyMap = [
            'trigger' => 'trigger',
            'interval' => 'interval',
            'method' => 'method',
            'thresholdCredits' => 'threshold_credits',
            'targetOngoingBalance' => 'target_ongoing_balance',
            'grantsTargetTopUp' => 'grants_target_top_up',
            'paidCredits' => 'paid_credits',
            'grantedCredits' => 'granted_credits',
            'startedAt' => 'started_at',
            'expirationAt' => 'expiration_at',
            'transactionName' => 'transaction_name',
            'invoiceRequiresSuccessfulPayment' => 'invoice_requires_successful_payment',
        ];

        $rules = [];

        foreach ((array) ($payload['recurringTransactionRules'] ?? []) as $rule) {
            $rule = (array) $rule;

            $entry = [];

            foreach ($keyMap as $source => $target) {
                if (array_key_exists($source, $rule) && $rule[$source] !== null) {
                    $entry[$target] = $rule[$source];
                }
            }

            $rules[] = $entry;
        }

        return $rules === [] ? null : $rules;
    }

    // -- Item accessors ---------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    protected function planItems(): array
    {
        return array_values((array) ($this->billingItems()['plans'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    protected function couponItems(): array
    {
        return array_values((array) ($this->billingItems()['coupons'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    protected function walletCreditItems(): array
    {
        return array_values((array) ($this->billingItems()['walletCredits'] ?? []));
    }

    /**
     * Subscriptions::CreateService assigns the negotiated amount onto the
     * plan it is given and Plans::OverrideService dups it, so two items on
     * the same plan get their own instance instead of inheriting each other's
     * amount.
     */
    protected function findPlan(Order $order, mixed $planId): Plan
    {
        $plan = $planId === null
            ? null
            : Plan::query()
                ->where('organization_id', $order->organization_id)
                ->whereKey($planId)
                ->first();

        if ($plan === null) {
            static::makeResult('order')->notFoundFailure('plan')->raiseIfError();
        }

        return $plan;
    }

    /** @return array<string, Coupon> */
    protected function couponsById(Order $order): array
    {
        if ($this->couponsById !== null) {
            return $this->couponsById;
        }

        $ids = array_map(fn (array $item) => $item['id'] ?? null, $this->couponItems());

        return $this->couponsById = Coupon::query()
            ->where('organization_id', $order->organization_id)
            ->whereKey(array_values(array_filter($ids)))
            ->get()
            ->keyBy('id')
            ->all();
    }
}
