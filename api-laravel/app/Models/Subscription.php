<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Subscriptions\DatesService;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `subscriptions` — refined with the Rails
 * Subscription model's relations, enums, scopes, concerns and instance
 * methods (app/models/subscription.rb).
 *
 * Frozen-schema legacy columns `incompleted_at` / `cancelation_reason`
 * (older spellings of `activated_at`-era fields) exist in the database but
 * are Rails-ignored — they are deliberately NOT fillable and not read.
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): lifetime_usage (LifetimeUsage model) — markAsActive() used to
 *   build/carry it across upgrade chains.
 * - TODO(port): activation_rules (Subscription::ActivationRule) — pending
 *   payment gating; pendingRules()/paymentGated() are stubbed false.
 * - TODO(port): usage_thresholds, entitlements, fixed_charge_events,
 *   fixed_charge_units_overrides, integration_resources,
 *   billing_object_connections, applied_invoice_custom_sections,
 *   Clickhouse activity logs.
 */
#[Fillable([
    'customer_id',
    'plan_id',
    'status',
    'canceled_at',
    'terminated_at',
    'started_at',
    'previous_subscription_id',
    'name',
    'external_id',
    'billing_time',
    'subscription_at',
    'ending_at',
    'trial_ended_at',
    'organization_id',
    'on_termination_credit_note',
    'on_termination_invoice',
    'payment_method_id',
    'payment_method_type',
    'skip_invoice_custom_sections',
    'progressive_billing_disabled',
    'last_received_event_on',
    'activated_at',
    'billing_entity_id',
    'consolidate_invoice',
    'skip_daily_usage',
    'cancellation_reason',
    'purchase_order_number',
    'billing_anchor_date',
])]
#[Table(name: 'subscriptions')]
class Subscription extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    /** Rails: Subscription::STATUSES — integer enum, see App\Enums\SubscriptionStatus. */
    public const STATUSES = ['pending', 'active', 'terminated', 'canceled', 'incomplete'];

    /** Rails: Subscription::BILLING_TIME — integer enum, see App\Enums\BillingTime. */
    public const BILLING_TIME = ['calendar', 'anniversary'];

    /** Rails: Subscription::ON_TERMINATION_CREDIT_NOTES (PG enum). */
    public const ON_TERMINATION_CREDIT_NOTES = ['credit', 'skip', 'refund', 'offset'];

    /** Rails: Subscription::ON_TERMINATION_INVOICES (PG enum). */
    public const ON_TERMINATION_INVOICES = ['generate', 'skip'];

    /** Rails: Subscription::CANCELLATION_REASONS (PG enum). */
    public const CANCELLATION_REASONS = ['payment_failed', 'timeout', 'manual'];

    /** Rails: HasPurchaseOrderNumber::PURCHASE_ORDER_NUMBER_MAX_LENGTH. */
    public const PURCHASE_ORDER_NUMBER_MAX_LENGTH = 255;

    /** Rails schema defaults, mirrored for new instances. */
    protected $attributes = [
        'billing_time' => 0,
        'on_termination_invoice' => 'generate',
        'payment_method_type' => 'provider',
        'skip_invoice_custom_sections' => false,
        'progressive_billing_disabled' => false,
        'consolidate_invoice' => true,
        'skip_daily_usage' => false,
    ];

    /** Rails: `subscription_at_in_timezone_sql`. */
    public static function subscriptionAtInTimezoneSql(): string
    {
        return "subscriptions.subscription_at::timestamptz AT TIME ZONE
            COALESCE(customers.timezone, organizations.timezone, 'UTC')";
    }

    /** Rails: `ending_at_in_timezone_sql`. */
    public static function endingAtInTimezoneSql(): string
    {
        return "subscriptions.ending_at::timestamptz AT TIME ZONE
            COALESCE(customers.timezone, organizations.timezone, 'UTC')";
    }

    // -- Relationships -----------------------------------------------------------

    /** Rails: `belongs_to :customer, -> { with_discarded }`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: `belongs_to :plan, -> { with_discarded }`. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function previousSubscription(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_subscription_id');
    }

    /** Rails: `has_many :next_subscriptions, foreign_key: :previous_subscription_id`. */
    public function nextSubscriptions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_subscription_id');
    }

    public function invoiceSubscriptions(): HasMany
    {
        return $this->hasMany(InvoiceSubscription::class);
    }

    /** Rails: `has_many :fees`. */
    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    /**
     * Rails: `has_many :fixed_charges, -> { kept }, through: :plan` —
     * non-discarded fixed charges of the plan.
     */
    public function fixedCharges(): HasManyThrough
    {
        return $this->hasManyThrough(
            FixedCharge::class,
            Plan::class,
            'id',
            'plan_id',
            'plan_id',
            'id',
        )->whereNull('fixed_charges.deleted_at');
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `scope :starting_in_the_future, -> { pending.where(previous_subscription: nil) }`. */
    public function scopeStartingInTheFuture(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Pending->value)
            ->whereNull('previous_subscription_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Pending->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value);
    }

    public function scopeTerminated(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Terminated->value);
    }

    public function scopeCanceled(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Canceled->value);
    }

    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Incomplete->value);
    }

    // -- Rails enum suffix helpers ------------------------------------------------

    public function pending(): bool
    {
        return $this->statusValue() === SubscriptionStatus::Pending->value;
    }

    public function active(): bool
    {
        return $this->statusValue() === SubscriptionStatus::Active->value;
    }

    public function terminated(): bool
    {
        return $this->statusValue() === SubscriptionStatus::Terminated->value;
    }

    public function canceled(): bool
    {
        return $this->statusValue() === SubscriptionStatus::Canceled->value;
    }

    public function incomplete(): bool
    {
        return $this->statusValue() === SubscriptionStatus::Incomplete->value;
    }

    /** The stored integer status, whatever the assignment form was. */
    public function statusValue(): ?int
    {
        $raw = $this->getRawOriginal('status');

        return $raw === null ? null : (int) $raw;
    }

    /** The Rails enum name (the string the REST API emits). */
    public function statusName(): ?string
    {
        $value = $this->statusValue();

        return $value === null ? null : SubscriptionStatus::from($value)->label();
    }

    public function calendar(): bool
    {
        return (int) $this->getRawOriginal('billing_time') === BillingTime::Calendar->value;
    }

    /** Rails: `anniversary?` — billing_time enum suffix helper. */
    public function anniversary(): bool
    {
        return (int) $this->getRawOriginal('billing_time') === BillingTime::Anniversary->value;
    }

    /** The stored integer billing_time. */
    public function billingTimeValue(): int
    {
        return (int) $this->getRawOriginal('billing_time');
    }

    // -- State transitions (Rails mark_as_*! bang methods) -------------------------

    /** Rails: `mark_as_active!(timestamp = Time.current)`. */
    public function markAsActive(CarbonInterface|int|string|null $timestamp = null): static
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        $this->started_at ??= $timestamp;
        $this->activated_at ??= $timestamp;
        // TODO(port): self.lifetime_usage ||= previous_subscription&.lifetime_usage ||
        //   build_lifetime_usage(organization:) — LifetimeUsage model is not ported yet.
        $this->status = SubscriptionStatus::Active->value;

        return $this;
    }

    /** Rails: `mark_as_terminated!(timestamp = Time.current)`. */
    public function markAsTerminated(CarbonInterface|int|string|null $timestamp = null): static
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        $this->terminated_at ??= $timestamp;
        $this->status = SubscriptionStatus::Terminated->value;

        return $this;
    }

    /** Rails: `mark_as_canceled!`. */
    public function markAsCanceled(): static
    {
        $this->canceled_at ??= now();
        $this->status = SubscriptionStatus::Canceled->value;

        return $this;
    }

    /** Rails: `mark_as_incomplete!(timestamp = Time.current)`. */
    public function markAsIncomplete(CarbonInterface|int|string|null $timestamp = null): static
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        $this->started_at ??= $timestamp;
        $this->status = SubscriptionStatus::Incomplete->value;

        return $this;
    }

    // -- Domain methods (ports of the Rails instance methods) ---------------------

    /** Rails: `pending_rules?` — any activation rule still pending. */
    public function pendingRules(): bool
    {
        // TODO(port): activation_rules.pending.any? — Subscription::ActivationRule
        // model is not ported yet; no subscription is gated meanwhile.
        return false;
    }

    /** Rails: `gated?`. */
    public function gated(): bool
    {
        return $this->pendingRules() && $this->incomplete();
    }

    /** Rails: `payment_gated?`. */
    public function paymentGated(): bool
    {
        // TODO(port): activation_rules.payment.pending.any?
        return false;
    }

    /** Rails: `upgraded?`. */
    public function upgraded(): bool
    {
        $next = $this->nextSubscription();

        if ($next === null) {
            return false;
        }

        return $this->plan->yearlyAmountCents() <= $next->plan->yearlyAmountCents();
    }

    /** Rails: `downgraded?`. */
    public function downgraded(): bool
    {
        $next = $this->nextSubscription();

        if ($next === null) {
            return false;
        }

        return $this->plan->yearlyAmountCents() > $next->plan->yearlyAmountCents();
    }

    /**
     * The anchor a rate card inherits when it is created without one: the
     * subscription's explicit anchor, else the day the subscription started.
     */
    public function effectiveBillingAnchorDate(): ?CarbonInterface
    {
        if ($this->billing_anchor_date !== null) {
            return CarbonImmutable::parse($this->getRawOriginal('billing_anchor_date'));
        }

        $reference = $this->started_at ?? $this->subscription_at;

        return $reference === null ? null : CarbonImmutable::instance($reference)->startOfDay();
    }

    /** Rails: `trial_end_date` (a date, plan.has_trial? gated). */
    public function trialEndDate(): ?CarbonInterface
    {
        if (! $this->plan->hasTrial()) {
            return null;
        }

        return $this->trialEndDatetime()?->startOfDay();
    }

    /** Rails: `trial_end_datetime`. */
    public function trialEndDatetime(): ?CarbonInterface
    {
        if (! $this->plan->hasTrial()) {
            return null;
        }

        return $this->initialStartedAt()?->addDays((int) ceil((float) $this->plan->trial_period));
    }

    /** Rails: `in_trial_period?`. */
    public function inTrialPeriod(): bool
    {
        if ($this->trial_ended_at !== null) {
            return false;
        }

        $initialStartedAt = $this->initialStartedAt();

        if ($initialStartedAt === null || $initialStartedAt->isFuture()) {
            return false;
        }

        $trialEndDatetime = $this->trialEndDatetime();

        return $trialEndDatetime !== null && $trialEndDatetime->isFuture();
    }

    /** Rails: `started_in_past?`. */
    public function startedInPast(): bool
    {
        return CarbonImmutable::instance($this->started_at)->startOfDay()
            ->lt(CarbonImmutable::instance($this->created_at)->startOfDay());
    }

    /**
     * Falls back to started_at when the subscription has not started yet, so
     * the billing period is computed from its first period rather than the
     * period around Time.current.
     */
    public function billingReferenceTime(): CarbonInterface
    {
        $startedAt = $this->started_at;

        if ($startedAt === null) {
            return now();
        }

        return now()->gt(CarbonImmutable::instance($startedAt)) ? now() : CarbonImmutable::instance($startedAt);
    }

    /**
     * Rails: `initial_started_at` — walks the external_id chain to the
     * earliest started_at of the family, falling back to subscription_at.
     */
    public function initialStartedAt(): ?CarbonInterface
    {
        $first = static::query()
            ->where('external_id', $this->external_id)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->first();

        return $first?->started_at ?? $this->subscription_at;
    }

    /** Rails: `next_subscription` — latest created, non-canceled next subscription. */
    public function nextSubscription(): ?self
    {
        return $this->nextSubscriptions()
            ->where('status', '!=', SubscriptionStatus::Canceled->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function alreadyBilled(): bool
    {
        // fee_type integer enum: charge=0, add_on=1, subscription=2, …
        return $this->fees()->where('fee_type', 2)->exists();
    }

    /** Rails: `starting_in_the_future?`. */
    public function startingInTheFuture(): bool
    {
        return $this->pending() && $this->previous_subscription_id === null;
    }

    /** Rails: Terminatable#terminated_at?. */
    public function terminatedAt(CarbonInterface|int|null $timestamp): bool
    {
        if (! $this->terminated()) {
            return false;
        }

        if ($this->terminated_at === null || $timestamp === null) {
            return false;
        }

        $terminatedAt = CarbonImmutable::instance($this->terminated_at)->roundSecond();
        $at = is_int($timestamp)
            ? CarbonImmutable::createFromTimestampUTC($timestamp)
            : CarbonImmutable::instance($timestamp)->utc();

        return $terminatedAt->lte($at->roundSecond());
    }

    /** Rails: `downgrade_plan_date`. */
    public function downgradePlanDate(): ?CarbonInterface
    {
        $nextSubscription = $this->nextSubscription();

        if ($nextSubscription === null) {
            return null;
        }

        if ($nextSubscription->active() && $this->downgraded()) {
            $startedAt = $nextSubscription->started_at;

            return $startedAt === null ? null : CarbonImmutable::instance($startedAt)->startOfDay();
        }

        if (! $this->pending()) {
            return null;
        }

        return DatesService::newInstance($this, now())
            ->nextEndOfPeriod()
            ->addDay()
            ->startOfDay();
    }

    /** Rails: `display_name`. */
    public function displayName(): string
    {
        $name = $this->name;

        return ($name !== null && $name !== '') ? $name : $this->plan->name;
    }

    /** Rails: `invoice_name`. */
    public function invoiceName(): ?string
    {
        $name = $this->name;

        return ($name !== null && $name !== '') ? $name : $this->plan->invoiceName();
    }

    /** Rails: `has_progressive_billing?`. */
    public function hasProgressiveBilling(): bool
    {
        return count($this->applicableUsageThresholds()) > 0;
    }

    /**
     * Rails: `applicable_usage_thresholds` — direct thresholds override; plan
     * thresholds otherwise.
     *
     * TODO(port): usage_thresholds model is not ported yet; returns [] until
     * progressive billing is ported (threshold columns live in
     * `usage_thresholds`, M1 task 9 territory).
     *
     * @return list<mixed>
     */
    public function applicableUsageThresholds(): array
    {
        return [];
    }

    /** Rails: `last_subscription_fee`. */
    public function lastSubscriptionFee(): ?Fee
    {
        return $this->fees()
            ->where('fee_type', 2)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    // -- Validations ---------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->external_id ?? '') === '') {
            $errors['external_id'] = ['value_is_mandatory'];
        }

        if ($this->getRawOriginal('billing_time') === null) {
            $errors['billing_time'] = ['value_is_mandatory'];
        }

        // Rails: validate_external_id, on create/update when the status is
        // being changed to active or incomplete.
        if (
            $this->exists
            && $this->isDirty('status')
            && in_array($this->statusValue(), [SubscriptionStatus::Active->value, SubscriptionStatus::Incomplete->value], true)
            && $this->organization_id !== null
        ) {
            $duplicate = static::query()
                ->where('organization_id', $this->organization_id)
                ->where('status', $this->statusValue())
                ->where('external_id', $this->external_id);

            if ($this->exists) {
                $duplicate->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($duplicate->exists()) {
                $errors['external_id'] = ['value_already_exist'];
            }
        }

        $purchaseOrderNumber = $this->purchase_order_number;

        if ($purchaseOrderNumber !== null && mb_strlen($purchaseOrderNumber) > self::PURCHASE_ORDER_NUMBER_MAX_LENGTH) {
            $errors['purchase_order_number'] = ['value_is_too_long'];
        }

        return $errors;
    }

    /** Accepts Carbon instances, unix timestamps and datetime strings. */
    protected function normalizeTimestamp(CarbonInterface|int|string|null $timestamp): CarbonInterface
    {
        if ($timestamp === null) {
            return now();
        }

        if ($timestamp instanceof CarbonInterface) {
            return $timestamp;
        }

        return is_int($timestamp)
            ? CarbonImmutable::createFromTimestampUTC($timestamp)
            : CarbonImmutable::parse($timestamp, 'UTC');
    }

    // -- Attribute normalization ---------------------------------------------------

    /**
     * Rails (HasPurchaseOrderNumber): the attribute is normalized on
     * assignment — trimmed, blank-to-nil.
     */
    protected function purchaseOrderNumber(): Attribute
    {
        return Attribute::set(function ($value) {
            if ($value === null) {
                return null;
            }

            $trimmed = is_string($value) ? mb_trim($value) : $value;

            return ($trimmed === '') ? null : $trimmed;
        });
    }

    /**
     * Rails: `status=` assigns the enum NAME ("pending"…) and the column
     * stores the integer position. Unmapped values pass through raw so the
     * validation reports them (never silently coerced).
     */
    protected function status(): Attribute
    {
        return Attribute::set(function ($value) {
            if ($value === null) {
                return null;
            }

            return SubscriptionStatus::fromOption($value) ?? $value;
        });
    }

    /**
     * Rails: `billing_time=` assigns the enum NAME ("calendar"…) and the
     * column stores the integer position. Unmapped values pass through raw.
     */
    protected function billingTime(): Attribute
    {
        return Attribute::set(function ($value) {
            if ($value === null) {
                return null;
            }

            return BillingTime::fromOption($value) ?? $value;
        });
    }

    protected function casts(): array
    {
        return [
            'canceled_at' => 'datetime',
            'terminated_at' => 'datetime',
            'started_at' => 'datetime',
            'subscription_at' => 'datetime',
            'ending_at' => 'datetime',
            'trial_ended_at' => 'datetime',
            'skip_invoice_custom_sections' => 'boolean',
            'progressive_billing_disabled' => 'boolean',
            'last_received_event_on' => 'date:Y-m-d',
            'activated_at' => 'datetime',
            'consolidate_invoice' => 'boolean',
            'skip_daily_usage' => 'boolean',
            'billing_anchor_date' => 'date:Y-m-d',
        ];
    }
}
