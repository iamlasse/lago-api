<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Plan;
use App\Models\Customer;
use App\Enums\BillingTime;
use Carbon\CarbonImmutable;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\Utils\Datetime;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use App\Services\BillingEntities\ResolveService;
use App\Services\Customers\UpdateCurrencyService;

/**
 * Port of Rails' Subscriptions::CreateService
 * (app/services/subscriptions/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): plan_overrides (License-gated premium) — Plans::OverrideService
 *   and Subscription::FixedChargeUnitsOverride are not ported; a request
 *   carrying plan_overrides still fails with forbidden when non-premium, and
 *   the premium override branches are deferred at the marked hooks.
 * - TODO(port): Subscriptions::ActivationRules::ApplyService.
 * - TODO(port): UpdateUsageThresholdsService (usage_thresholds).
 * - TODO(port): InvoiceCustomSections::AttachToResourceService.
 * - TODO(port): BillingObjectConnections::AttachToResourceService.
 * - TODO(port): EmitFixedChargeEventsService.
 * - TODO(port): SendWebhookJob emissions (subscription.started) and
 *   BillSubscriptionJob/Invoices::CreatePayInAdvanceFixedChargesJob — task 9
 *   owns the billing side; hooks are marked inline.
 * - TODO(port): Hubspot/ActivityLog side effects.
 */
class CreateService extends BaseService
{
    protected Customer $customer;

    protected Plan $plan;

    /** @var array<string, mixed> */
    protected array $params;

    protected string $name;

    protected CarbonImmutable $subscriptionAt;

    protected ?string $billingTime;

    protected string $externalId;

    protected BaseResult $result;

    protected ?Subscription $currentSubscription = null;

    public function __construct(
        Customer $customer,
        Plan $plan,
        array $params,
    ) {
        parent::__construct();

        $this->customer = $customer;
        $this->plan = $plan;
        $this->params = $params;

        $this->name = mb_trim((string) ($params['name'] ?? ''));
        $this->subscriptionAt = $this->toCarbon($params['subscription_at'] ?? null) ?? CarbonImmutable::now();
        $this->billingTime = isset($params['billing_time']) && $params['billing_time'] !== null
            ? (string) $params['billing_time']
            : null;
        $this->externalId = mb_trim((string) ($params['external_id'] ?? ''));
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('subscription', 'payment_method');
        $this->result = $result;

        $params = $this->params;

        $result->payment_method = $this->paymentMethod();

        $valid = (new ValidateService($result, [
            'customer' => $this->customer,
            'plan' => $this->plan,
            // Rails validates the RAW param (`@subscription_at` ivar —
            // params[:subscription_at] || Time.current), so an unparseable
            // string reaches valid_format? and fails with invalid_date.
            'subscription_at' => $params['subscription_at'] ?? $this->subscriptionAt,
            'ending_at' => $params['ending_at'] ?? null,
            'payment_method' => $params['payment_method'] ?? null,
            'connections' => $params['connections'] ?? null,
            'activation_rules' => $params['activation_rules'] ?? null,
            'subscription_type' => $this->subscriptionType(),
            'consolidate_invoice' => $params['consolidate_invoice'] ?? null,
            'consolidate_invoice_provided' => array_key_exists('consolidate_invoice', $params),
        ]))->valid();

        if (! $valid) {
            return $result;
        }

        if (! $this->premium() && array_key_exists('plan_overrides', $params)) {
            return $result->forbiddenFailure();
        }

        if ($this->connectionsRequested() && ! $this->organizationFlagEnabled($this->customer->organization, 'multi_connection')) {
            return $result->forbiddenFailure();
        }

        if (blank($params['external_customer_id'] ?? null) && $this->apiContext()) {
            return $result->validationFailure(['external_customer_id' => ['value_is_mandatory']]);
        }

        // TODO(port): Remove check we stop supporting `plan_overrides.usage_thresholds`
        if (! blank($params['usage_thresholds'] ?? null)
            && ! blank(is_array($params['plan_overrides'] ?? null) ? ($params['plan_overrides']['usage_thresholds'] ?? null) : null)
        ) {
            return $result->validationFailure([
                'plan_overrides.usage_thresholds' => ['incompatible_params'],
                'usage_thresholds' => ['incompatible_params'],
            ]);
        }

        $billingTimeValue = null;
        if ($this->billingTime !== null) {
            $billingTimeValue = BillingTime::fromOption($this->billingTime);

            if ($billingTimeValue === null) {
                return $result->validationFailure(['billing_time' => ['value_is_invalid']]);
            }
        }

        // NOTE: in API, it's possible to create a subscription for a new customer
        if ($this->apiContext()) {
            $errors = $this->customer->validateAttributes();
            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $this->customer->save();
        }

        try {
            DB::transaction(function () use ($result, $params, $billingTimeValue): void {
                UpdateCurrencyService::call(
                    customer: $this->customer,
                    currency: $this->plan->amount_currency,
                )->raiseIfError();

                // Rails: customer.with_lock — SELECT ... FOR UPDATE on the row.
                $customer = Customer::query()
                    ->whereKey($this->customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $customer->subscriptions()->incomplete()
                        ->where(function ($query) use ($params): void {
                            $query->where('id', $params['subscription_id'] ?? null)
                                ->orWhere('external_id', $this->externalId);
                        })
                        ->exists()
                ) {
                    $result->validationFailure(['subscription' => ['subscription_incomplete']])->raiseIfError();
                }

                $this->currentSubscription = $this->editableSubscriptions($customer)
                    ->where(function ($query) use ($params): void {
                        $query->where('id', $params['subscription_id'] ?? null)
                            ->orWhere('external_id', $this->externalId);
                    })
                    ->first();

                if (
                    $this->currentSubscription === null
                    && $customer->organization->subscriptions()->active()
                        ->where('external_id', $this->externalId)
                        ->exists()
                ) {
                    $result->validationFailure(['external_id' => ['value_already_exist']])->raiseIfError();
                }

                $subscription = $this->handleSubscription($customer, $billingTimeValue);

                // TODO(port): UpdateUsageThresholdsService.call! when
                // params[:usage_thresholds] is present — usage thresholds are
                // not ported yet.
                // TODO(port): InvoiceCustomSections::AttachToResourceService
                // and BillingObjectConnections::AttachToResourceService unless
                // downgrade — not ported yet.

                $result->subscription = $subscription;
            });

            return $result;
        } catch (\App\Services\Failures\FailedResult $e) {
            return $e->result ?? $this->embedFailure($result, $e);
        }
    }

    // -- Branching -----------------------------------------------------------------

    protected function handleSubscription(Customer $customer, ?int $billingTimeValue): Subscription
    {
        if ($this->upgrade()) {
            return PlanUpgradeService::call(
                currentSubscription: $this->currentSubscription,
                plan: $this->plan,
                params: $this->params,
            )->raiseIfError()->subscription;
        }

        if ($this->downgrade()) {
            return PlanDowngradeService::call(
                customer: $customer,
                currentSubscription: $this->currentSubscription,
                plan: $this->plan,
                params: $this->params,
            )->raiseIfError()->subscription;
        }

        return $this->currentSubscription ?? $this->createSubscription($customer, $billingTimeValue);
    }

    protected function upgrade(): bool
    {
        if ($this->currentSubscription === null) {
            return false;
        }

        if ($this->plan->id === $this->currentSubscription->plan->id) {
            return false;
        }

        return $this->plan->yearlyAmountCents() >= $this->currentSubscription->plan->yearlyAmountCents();
    }

    protected function downgrade(): bool
    {
        if ($this->currentSubscription === null) {
            return false;
        }

        if ($this->plan->id === $this->currentSubscription->plan->id) {
            return false;
        }

        return $this->plan->yearlyAmountCents() < $this->currentSubscription->plan->yearlyAmountCents();
    }

    protected function subscriptionType(): string
    {
        if ($this->downgrade()) {
            return 'downgrade';
        }

        if ($this->upgrade()) {
            return 'upgrade';
        }

        return 'create';
    }

    // -- New subscription creation ---------------------------------------------------

    protected function createSubscription(Customer $customer, ?int $billingTimeValue): Subscription
    {
        $newSubscription = new Subscription([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'plan_id' => $this->plan->id,
            'subscription_at' => $this->subscriptionAt,
            'name' => $this->name,
            'external_id' => $this->externalId,
            'billing_time' => $billingTimeValue ?? BillingTime::Calendar->value,
            'ending_at' => $this->params['ending_at'] ?? null,
            'purchase_order_number' => $this->params['purchase_order_number'] ?? null,
            'progressive_billing_disabled' => $this->params['progressive_billing_disabled'] ?? false,
            'consolidate_invoice' => $this->consolidateInvoice(),
        ]);

        // TODO(port): plan_overrides — target_plan_for_new_subscription
        // (Plans::OverrideService) and the units-only fixed-charge overrides
        // branch are deferred with the premium override work.

        $paymentMethod = $this->params['payment_method'] ?? null;
        if (is_array($paymentMethod)) {
            if (array_key_exists('payment_method_type', $paymentMethod)) {
                $newSubscription->payment_method_type = $paymentMethod['payment_method_type'];
            }

            if (array_key_exists('payment_method_id', $paymentMethod)) {
                $newSubscription->payment_method_id = $paymentMethod['payment_method_id'];
            }
        }

        // TODO(port): resolve_billing_entity — BillingEntities::ResolveService
        // is invoked with params when the API sends billing_entity_code/id; the
        // column is set from the customer's resolved entity meanwhile.
        if (($this->params['billing_entity_id'] ?? null) !== null) {
            $newSubscription->billing_entity_id = $this->params['billing_entity_id'];
        } elseif ($customer->billing_entity_id !== null) {
            $newSubscription->billing_entity_id = $customer->billing_entity_id;
        }

        $timezone = $customer->applicableTimezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $subscriptionDate = $this->subscriptionAt->setTimezone('UTC')->setTimezone($timezone)->startOfDay();

        $errors = $newSubscription->validateAttributes();
        if ($errors !== []) {
            $this->result->recordValidationFailure($errors)->raiseIfError();
        }

        if ($subscriptionDate->equalTo($today)) {
            $this->handleTodaySubscription($newSubscription);
        } elseif ($subscriptionDate->lessThan($today)) {
            $this->handlePastSubscription($newSubscription);
        } else {
            $this->handleFutureSubscription($newSubscription);
        }

        return $newSubscription;
    }

    protected function handleTodaySubscription(Subscription $newSubscription): void
    {
        $newSubscription->status = SubscriptionStatus::Pending->value;
        $newSubscription->save();

        // TODO(port): apply_activation_rules — Subscription::ActivationRules::ApplyService.

        ActivateService::call(
            subscription: $newSubscription,
            timestamp: $newSubscription->subscription_at,
        )->raiseIfError();
    }

    protected function handlePastSubscription(Subscription $newSubscription): void
    {
        $newSubscription->markAsActive($this->startedAtFor($newSubscription));
        $newSubscription->save();

        // TODO(port): EmitFixedChargeEventsService.call!(
        //   subscriptions: [new_subscription],
        //   timestamp: new_subscription.started_at + 1.second)

        // TODO(port): SendWebhookJob.perform_later("subscription.started", ...)
        // TODO(port): Utils::ActivityLog.produce(subscription, "subscription.started")
        // TODO(port): Integrations::Aggregator::Subscriptions::Hubspot::CreateJob
    }

    protected function handleFutureSubscription(Subscription $newSubscription): void
    {
        $newSubscription->status = SubscriptionStatus::Pending->value;
        $newSubscription->save();

        // TODO(port): apply_activation_rules — Subscription::ActivationRules::ApplyService.
    }

    /**
     * NOTE: Backdating normally means "this subscription really started then,
     * bill it from then". But a previous subscription on the same external_id
     * may have already invoiced part of that window when it terminated.
     * Clamping to that boundary honours the backdate as far back as is safe.
     */
    protected function startedAtFor(Subscription $newSubscription): CarbonImmutable
    {
        $startedAt = $this->subscriptionAt;

        $lastInvoiced = $this->lastInvoicedTerminationTime($newSubscription);

        if ($lastInvoiced !== null && $lastInvoiced->gt($startedAt)) {
            return $lastInvoiced;
        }

        return $startedAt;
    }

    /**
     * NOTE: A subscription terminated with `on_termination_invoice: skip`
     * never billed its last window, so it closes nothing and the backdated
     * period must stay billable.
     */
    protected function lastInvoicedTerminationTime(Subscription $newSubscription): ?CarbonImmutable
    {
        $max = Subscription::query()
            ->where('customer_id', $this->customer->id)
            ->where('status', SubscriptionStatus::Terminated->value)
            ->where('external_id', $this->externalId)
            ->where('on_termination_invoice', 'generate')
            ->max('terminated_at');

        return $max !== null ? CarbonImmutable::parse($max)->utc() : null;
    }

    /**
     * Rails: `editable_subscriptions` — active subscriptions plus pending
     * ones starting in the future, newest started first.
     */
    protected function editableSubscriptions(Customer $customer)
    {
        return Subscription::query()
            ->where('customer_id', $customer->id)
            ->where(function ($query): void {
                $query->where('status', SubscriptionStatus::Active->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', SubscriptionStatus::Pending->value)
                            ->whereNull('previous_subscription_id');
                    });
            })
            ->orderByDesc('started_at');
    }

    protected function consolidateInvoice(): bool
    {
        if (! array_key_exists('consolidate_invoice', $this->params)) {
            return true;
        }

        return filter_var($this->params['consolidate_invoice'], FILTER_VALIDATE_BOOLEAN);
    }

    protected function paymentMethod(): mixed
    {
        // TODO(port): PaymentMethod model — Rails looks up
        // PaymentMethod.find_by(id:, organization_id:) and exposes it on the
        // result; payment methods are a later milestone, so this stays null.
        return null;
    }

    protected function connectionsRequested(): bool
    {
        return ! blank($this->params['connections'] ?? null);
    }

    protected function organizationFlagEnabled(Organization $organization, string $flag): bool
    {
        return in_array($flag, (array) ($organization->feature_flags ?? []), true);
    }

    protected function toCarbon(mixed $value): ?CarbonImmutable
    {
        return Datetime::parseIso8601($value);
    }
}
