<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Subscriptions\DatesService;
use App\Models\Concerns\ConnectionResolvable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * - entitlements, fixed_charge_events,
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
    use ConnectionResolvable;
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

    /** Ledger row built by markAsActive before the parent has an id. */
    protected ?LifetimeUsage $pendingLifetimeUsage = null;

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

    /**
     * Rails: `has_many :billing_object_connections, as: :owner`. The column
     * stores the Rails class name, so the type constraint is railsName()
     * rather than a Laravel morph map.
     */
    public function billingObjectConnections(): HasMany
    {
        return $this->hasMany(BillingObjectConnection::class, 'owner_id')
            ->where('owner_type', $this->railsName());
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

    // -- Usage monitoring (usage-monitoring slice, appended) ---------------------

    /**
     * Rails: has_many :alerts, ->(s) { where(organization_id: s.organization_id) },
     *   foreign_key: :subscription_external_id, primary_key: :external_id.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(UsageMonitoring\Alert::class, 'subscription_external_id', 'external_id')
            ->where('organization_id', $this->organization_id);
    }

    /** Rails: has_many :subscription_activities (usage-monitoring slice). */
    public function subscriptionActivities(): HasMany
    {
        return $this->hasMany(UsageMonitoring\SubscriptionActivity::class);
    }

    // -- State transitions (Rails mark_as_*! bang methods) -------------------------

    /** Rails: `mark_as_active!(timestamp = Time.current)`. */
    public function markAsActive(CarbonInterface|int|string|null $timestamp = null): static
    {
        $timestamp = $this->normalizeTimestamp($timestamp);

        $this->started_at ??= $timestamp;
        $this->activated_at ??= $timestamp;

        // Rails: self.lifetime_usage ||= previous_subscription&.lifetime_usage ||
        //   build_lifetime_usage(organization:) — the ledger is carried across
        //   upgrade/downgrade chains. Rails persists it through the
        //   subscription's autosave on the next save; the port defers the write
        //   the same way (this instance's save() flushes it after the parent
        //   has an id).
        $lifetimeUsage = $this->lifetimeUsage ?? $this->previousSubscription?->lifetimeUsage;
        $lifetimeUsage ??= $this->buildLifetimeUsage();
        $lifetimeUsage->recalculate_invoiced_usage = true;
        $this->pendingLifetimeUsage = $lifetimeUsage;
        $this->setRelation('lifetimeUsage', $lifetimeUsage);

        $this->status = SubscriptionStatus::Active->value;

        return $this;
    }

    /**
     * Flushes the lifetime-usage ledger built in markAsActive after the
     * subscription has an id (Rails' has_one autosave equivalent).
     */
    public function save(array $options = []): bool
    {
        $saved = parent::save($options);

        if ($this->pendingLifetimeUsage !== null && $this->exists) {
            $lifetimeUsage = $this->pendingLifetimeUsage;
            $this->pendingLifetimeUsage = null;
            $lifetimeUsage->subscription_id = $this->id;
            $lifetimeUsage->save();
            $this->setRelation('lifetimeUsage', $lifetimeUsage);
        }

        return $saved;
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

    /**
     * Rails: `has_many :applied_invoice_custom_sections,
     * class_name: "Subscription::AppliedInvoiceCustomSection", dependent: :destroy`
     * (the `subscriptions_invoice_custom_sections` table).
     */
    public function appliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(SubscriptionAppliedInvoiceCustomSection::class, 'subscription_id');
    }

    /** Rails: `has_many :selected_invoice_custom_sections, through: :applied_invoice_custom_sections, source: :invoice_custom_section`. */
    public function selectedInvoiceCustomSections(): BelongsToMany
    {
        return $this->belongsToMany(
            InvoiceCustomSection::class,
            'subscriptions_invoice_custom_sections',
            'subscription_id',
            'invoice_custom_section_id',
        )->whereNull('invoice_custom_sections.deleted_at');
    }
    // -- Domain methods (ports of the Rails instance methods) ---------------------

    /** Rails: `pending_rules?` — any activation rule still pending. */
    public function pendingRules(): bool
    {
        return $this->activationRules()
            ->where('subscription_activation_rules.status', 'pending')
            ->exists();
    }

    /** Rails: `gated?`. */
    public function gated(): bool
    {
        return $this->pendingRules() && $this->incomplete();
    }

    /** Rails: `payment_gated?`. */
    public function paymentGated(): bool
    {
        return $this->activationRules()
            ->where('subscription_activation_rules.type', 'payment')
            ->where('subscription_activation_rules.status', 'pending')
            ->exists();
    }

    /**
     * Rails: `has_many :activation_rules, class_name: "Subscription::ActivationRule"`.
     */
    public function activationRules(): HasMany
    {
        return $this->hasMany(Subscription\ActivationRule::class);
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
            ->oldest('started_at')
            ->first();

        return $first?->started_at ?? $this->subscription_at;
    }

    /** Rails: `next_subscription` — latest created, non-canceled next subscription. */
    public function nextSubscription(): ?self
    {
        return $this->nextSubscriptions()
            ->where('status', '!=', SubscriptionStatus::Canceled->value)
            ->latest()
            ->latest('id')
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

        if (! $nextSubscription->pending()) {
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
     * thresholds otherwise; the parent plan's when the plan is an override
     * child without its own. Answers [] when progressive billing is disabled
     * on the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, UsageThreshold>
     */
    public function applicableUsageThresholds(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->progressive_billing_disabled) {
            return UsageThreshold::query()->whereRaw('1 = 0')->get();
        }

        $direct = $this->usageThresholds;

        if ($direct->isNotEmpty()) {
            return $direct;
        }

        $planThresholds = $this->plan->usageThresholds;

        if ($planThresholds->isNotEmpty()) {
            return $planThresholds;
        }

        return $this->plan->applicableUsageThresholds();
    }

    /** Rails: has_many :usage_thresholds. */
    public function usageThresholds(): HasMany
    {
        return $this->hasMany(UsageThreshold::class);
    }

    /** Rails: has_one :lifetime_usage. */
    public function lifetimeUsage(): HasOne
    {
        return $this->hasOne(LifetimeUsage::class);
    }

    /**
     * Rails: `build_lifetime_usage` / `create_lifetime_usage!` — the lifetime
     * usage ledger row for this subscription.
     */
    public function buildLifetimeUsage(array $attributes = []): LifetimeUsage
    {
        return $this->lifetimeUsage()->make(array_merge([
            'organization_id' => $this->organization_id,
        ], $attributes));
    }

    public function createLifetimeUsage(array $attributes = []): LifetimeUsage
    {
        $lifetimeUsage = $this->buildLifetimeUsage($attributes);
        $lifetimeUsage->save();

        return $lifetimeUsage;
    }

    /** Rails: `last_subscription_fee`. */
    public function lastSubscriptionFee(): ?Fee
    {
        return $this->fees()
            ->where('fee_type', 2)
            ->latest()
            ->latest('id')
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

    // -- Scopes ------------------------------------------------------------------
    // Legacy scopeXyz() form: #[Scope] pending()/active()/terminated()/
    // canceled()/incomplete()/startingInTheFuture() would collide with the
    // same-named boolean status helpers below.

    /** Rails: `scope :starting_in_the_future, -> { pending.where(previous_subscription: nil) }`. */
    protected function scopeStartingInTheFuture(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Pending->value)
            ->whereNull('previous_subscription_id');
    }

    protected function scopePending(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Pending->value);
    }

    protected function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active->value);
    }

    protected function scopeTerminated(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Terminated->value);
    }

    protected function scopeCanceled(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Canceled->value);
    }

    protected function scopeIncomplete(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Incomplete->value);
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
