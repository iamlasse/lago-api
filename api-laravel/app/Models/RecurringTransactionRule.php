<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MoneyMath;
use App\Models\Casts\BcNumeric;
use App\Enums\RecurringTransactionMethod;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\RecurringTransactionTrigger;
use App\Enums\RecurringTransactionInterval;
use App\Enums\RecurringTransactionRuleStatus;
use App\Models\Concerns\ConnectionResolvable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' RecurringTransactionRule
 * (app/models/recurring_transaction_rule.rb) — a wallet's automatic top-up
 * configuration (interval or threshold triggered, fixed or target method).
 *
 * Not ported (TODO(port)): PaperTrail trace, activity log middleware,
 * BillingObjectConnections::AttachToResourceService writes (the
 * ConnectionResolvable read side is ported — the attach/validate services
 * are a later slice).
 */
#[Table(name: 'recurring_transaction_rules')]
#[Fillable([
    'organization_id',
    'wallet_id',
    'payment_method_id',
    'payment_method_type',
    'trigger',
    'interval',
    'method',
    'status',
    'paid_credits',
    'granted_credits',
    'threshold_credits',
    'target_ongoing_balance',
    'grants_target_top_up',
    'started_at',
    'expiration_at',
    'terminated_at',
    'invoice_requires_successful_payment',
    'ignore_paid_top_up_limits',
    'transaction_name',
    'transaction_metadata',
    'skip_invoice_custom_sections',
    'purchase_order_number',
])]
class RecurringTransactionRule extends BaseModel
{
    use ConnectionResolvable;
    use HasFactory;

    /** Rails: HasPurchaseOrderNumber::PURCHASE_ORDER_NUMBER_MAX_LENGTH. */
    public const PURCHASE_ORDER_NUMBER_MAX_LENGTH = 255;

    /** Rails: STATUSES / INTERVALS / METHODS / TRIGGERS (order = stored integer). */
    public const STATUSES = ['active', 'terminated'];

    public const INTERVALS = ['weekly', 'monthly', 'quarterly', 'yearly', 'semiannual'];

    public const METHODS = ['fixed', 'target'];

    public const TRIGGERS = ['interval', 'threshold'];

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so the NOT NULL DEFAULT columns are
     * declared here to keep reads (and the serializers) identical to Rails.
     */
    protected $attributes = [
        'trigger' => 0,
        'paid_credits' => '0',
        'granted_credits' => '0',
        'threshold_credits' => '0',
        'interval' => 0,
        'method' => 0,
        'invoice_requires_successful_payment' => false,
        'status' => 0,
        'ignore_paid_top_up_limits' => false,
        'payment_method_type' => 'provider',
        'skip_invoice_custom_sections' => false,
    ];

    // -- Relations ---------------------------------------------------------------

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function billingObjectConnections(): MorphMany
    {
        return $this->morphMany(BillingObjectConnection::class, 'owner');
    }

    /**
     * Rails: `has_many :applied_invoice_custom_sections,
     * class_name: "RecurringTransactionRule::AppliedInvoiceCustomSection",
     * dependent: :destroy` (the `recurring_transaction_rules_invoice_custom_sections`
     * table).
     */
    public function appliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(RecurringTransactionRuleAppliedInvoiceCustomSection::class, 'recurring_transaction_rule_id');
    }

    /** Rails: `has_many :selected_invoice_custom_sections, through: :applied_invoice_custom_sections, source: :invoice_custom_section`. */
    public function selectedInvoiceCustomSections(): BelongsToMany
    {
        return $this->belongsToMany(
            InvoiceCustomSection::class,
            'recurring_transaction_rules_invoice_custom_sections',
            'recurring_transaction_rule_id',
            'invoice_custom_section_id',
        );
    }

    /**
     * Rails: `delegate :customer, to: :wallet`. NOTE: kept as a plain
     * method (like WalletTransaction) — there is no customer_id column, so
     * ConnectionResolvable's connection resolution is overridden below to
     * route through the wallet.
     */
    public function customer(): ?Customer
    {
        return $this->wallet->customer;
    }

    // -- Rails enum accessors ----------------------------------------------------

    public function statusEnum(): ?RecurringTransactionRuleStatus
    {
        if ($this->status === null) {
            return null;
        }

        return $this->status instanceof RecurringTransactionRuleStatus
            ? $this->status
            : RecurringTransactionRuleStatus::tryFrom((int) $this->status);
    }

    public function intervalEnum(): ?RecurringTransactionInterval
    {
        if ($this->interval === null) {
            return null;
        }

        return $this->interval instanceof RecurringTransactionInterval
            ? $this->interval
            : RecurringTransactionInterval::tryFrom((int) $this->interval);
    }

    public function methodEnum(): ?RecurringTransactionMethod
    {
        if ($this->method === null) {
            return null;
        }

        return $this->method instanceof RecurringTransactionMethod
            ? $this->method
            : RecurringTransactionMethod::tryFrom((int) $this->method);
    }

    public function triggerEnum(): ?RecurringTransactionTrigger
    {
        if ($this->trigger === null) {
            return null;
        }

        return $this->trigger instanceof RecurringTransactionTrigger
            ? $this->trigger
            : RecurringTransactionTrigger::tryFrom((int) $this->trigger);
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === RecurringTransactionRuleStatus::Active;
    }

    public function isTerminated(): bool
    {
        return $this->statusEnum() === RecurringTransactionRuleStatus::Terminated;
    }

    public function isTarget(): bool
    {
        return $this->methodEnum() === RecurringTransactionMethod::Target;
    }

    public function isFixed(): bool
    {
        return $this->methodEnum() === RecurringTransactionMethod::Fixed;
    }

    public function isInterval(): bool
    {
        return $this->triggerEnum() === RecurringTransactionTrigger::Interval;
    }

    public function isThreshold(): bool
    {
        return $this->triggerEnum() === RecurringTransactionTrigger::Threshold;
    }

    // -- Rails helpers -----------------------------------------------------------

    /** Rails: `currently_active?`. */
    public function currentlyActive(): bool
    {
        return $this->isActive()
            && ($this->expiration_at === null || $this->expiration_at->gt(now()));
    }

    /** Rails: `mark_as_terminated!(timestamp = Time.zone.now)`. */
    public function markAsTerminated(mixed $timestamp = null): void
    {
        $this->terminated_at ??= $timestamp ?? now();

        $this->status = RecurringTransactionRuleStatus::Terminated->value;

        $this->save();
    }

    /** Rails: `apply_min_top_up_limits(credit_amount:)` — Ruby's clamp(min, nil). */
    public function applyMinTopUpLimits(string|int|float $creditAmount): string
    {
        if ((bool) $this->ignore_paid_top_up_limits) {
            return (string) $creditAmount;
        }

        $minCredits = $this->wallet->paidTopUpMinCredits();

        if ($minCredits === null) {
            return (string) $creditAmount;
        }

        return bccomp((string) $creditAmount, $minCredits, 5) === -1
            ? $minCredits
            : (string) $creditAmount;
    }

    /** Rails: `apply_max_top_up_limits(credit_amount:)` — Ruby's clamp(nil, max). */
    public function applyMaxTopUpLimits(string|int|float $creditAmount): string
    {
        if ((bool) $this->ignore_paid_top_up_limits) {
            return (string) $creditAmount;
        }

        $maxCredits = $this->wallet->paidTopUpMaxCredits();

        if ($maxCredits === null) {
            return (string) $creditAmount;
        }

        return bccomp((string) $creditAmount, $maxCredits, 5) === 1
            ? $maxCredits
            : (string) $creditAmount;
    }

    /**
     * Rails: `invoice_custom_section_params` — nil when the rule selects no
     * sections and does not skip them (the caller then omits the key).
     *
     * @return array{skip_invoice_custom_sections: bool, invoice_custom_section_ids: list<string>}|null
     */
    public function invoiceCustomSectionParams(): ?array
    {
        $sectionIds = $this->appliedInvoiceCustomSections()->pluck('invoice_custom_section_id')->all();

        if ($sectionIds === [] && ! (bool) $this->skip_invoice_custom_sections) {
            return null;
        }

        return [
            'skip_invoice_custom_sections' => (bool) $this->skip_invoice_custom_sections,
            'invoice_custom_section_ids' => array_values($sectionIds),
        ];
    }

    /** Rails: `resolved_purchase_order_number` — the rule's, else the wallet's. */
    public function resolvedPurchaseOrderNumber(): ?string
    {
        return ($this->purchase_order_number ?? '') !== ''
            ? $this->purchase_order_number
            : $this->wallet->purchase_order_number;
    }

    /**
     * Rails: `compute_paid_credits(ongoing_balance:, pending_credits: 0)` —
     * the paid part of the top-up for one application of the rule.
     */
    public function computePaidCredits(string|int|float $ongoingBalance, string|int|float $pendingCredits = 0): string
    {
        if ($this->isTarget()) {
            if ((bool) $this->grants_target_top_up) {
                return '0.0';
            }

            return $this->computeTargetTopUpAmount(ongoingBalance: $ongoingBalance);
        }

        if ($this->isThreshold()) {
            return $this->computeFixedTopUpAmount(ongoingBalance: $ongoingBalance, pendingCredits: $pendingCredits);
        }

        return (string) $this->paid_credits;
    }

    /** Rails: `compute_granted_credits`. */
    public function computeGrantedCredits(): string
    {
        if ($this->isTarget()) {
            if ((bool) $this->grants_target_top_up) {
                return $this->computeTargetTopUpAmount(ongoingBalance: (string) $this->wallet->credits_ongoing_balance);
            }

            return '0.0';
        }

        return (string) $this->granted_credits;
    }

    // -- Validation --------------------------------------------------------------

    /**
     * Port of the model validations the services rely on — a
     * field => [codes] hash, empty when valid. Rails' `normalizes
     * :purchase_order_number` (strip, blank → nil) runs on assignment before
     * validation; applied here so callers saving after a passing
     * validateAttributes() see the same stored value.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // Rails `normalizes :purchase_order_number, with: ->(value) { value.strip.presence }`.
        if (is_string($this->purchase_order_number)) {
            $this->purchase_order_number = mb_trim($this->purchase_order_number);

            if ($this->purchase_order_number === '') {
                $this->purchase_order_number = null;
            }
        }

        // validates :transaction_name, length: {minimum: 1, maximum: 255}, allow_nil: true
        if ($this->transaction_name !== null) {
            $length = mb_strlen($this->transaction_name);

            if ($length < 1 || $length > 255) {
                $errors['transaction_name'] = $length < 1 ? ['value_is_too_short'] : ['value_is_too_long'];
            }
        }

        // validates :grants_target_top_up, inclusion: {in: [true, false]},
        //   allow_nil: true, if: :target? — and exclusion: {in: [true, false]},
        //   unless: :target?. The column is boolean-cast, so every non-nil
        //   value is already true/false.
        if (! $this->isTarget() && $this->grants_target_top_up !== null) {
            $errors['grants_target_top_up'] = ['exclusion'];
        }

        if ($this->targetOngoingBalanceNotBelowThreshold()) {
            $errors['target_ongoing_balance'] = ['must_be_greater_than_or_equal_threshold'];
        }

        // Rails: HasPurchaseOrderNumber — length: {maximum: 255}, allow_nil: true.
        if ($this->purchase_order_number !== null && mb_strlen($this->purchase_order_number) > self::PURCHASE_ORDER_NUMBER_MAX_LENGTH) {
            $errors['purchase_order_number'] = ['value_is_too_long'];
        }

        return $errors;
    }

    /**
     * ConnectionResolvable override — the trait reads the `customer`
     * attribute, which has no relation behind it on this model; resolve
     * through the delegated customer instead (same logic, delegated source).
     */
    protected function customerDefaultConnection(string $category): ?object
    {
        $customer = $this->customer();

        if ($customer === null) {
            return null;
        }

        $connections = $category === 'payment'
            ? $customer->paymentProviderCustomers->all()
            : $customer->integrationCustomers
                ->filter(fn (IntegrationCustomer $connection): bool => $connection->category === $category)
                ->values()
                ->all();

        return $this->defaultAmong($connections);
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `scope :active` — status active AND not expired. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query
            ->where('status', RecurringTransactionRuleStatus::Active->value)
            ->where(fn (Builder $q) => $q
                ->whereNull('expiration_at')
                ->orWhere('expiration_at', '>', now()));
    }

    /** Rails: `scope :eligible_for_termination` — active rules past their expiration. */
    #[Scope]
    protected function eligibleForTermination(Builder $query): Builder
    {
        return $query
            ->where('status', RecurringTransactionRuleStatus::Active->value)
            ->whereNotNull('expiration_at')
            ->where('expiration_at', '<=', now());
    }

    /** Rails: `scope :expired` (any status past its expiration). */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->where('expiration_at', '<=', now());
    }

    /** Rails: `scope :terminated` (enum-generated). */
    #[Scope]
    protected function terminated(Builder $query): Builder
    {
        return $query->where('status', RecurringTransactionRuleStatus::Terminated->value);
    }

    protected function casts(): array
    {
        return [
            'paid_credits' => [BcNumeric::class, 'scale' => 5],
            'granted_credits' => [BcNumeric::class, 'scale' => 5],
            'threshold_credits' => [BcNumeric::class, 'scale' => 5],
            'target_ongoing_balance' => [BcNumeric::class, 'scale' => 5],
            'started_at' => 'datetime',
            'expiration_at' => 'datetime',
            'terminated_at' => 'datetime',
            'invoice_requires_successful_payment' => 'boolean',
            'ignore_paid_top_up_limits' => 'boolean',
            'grants_target_top_up' => 'boolean',
            'transaction_metadata' => 'array',
            'skip_invoice_custom_sections' => 'boolean',
        ];
    }

    /** Rails: `target_ongoing_balance_not_below_threshold` (runs only when the relevant fields changed). */
    private function targetOngoingBalanceNotBelowThreshold(): bool
    {
        if (! (
            $this->isDirty('target_ongoing_balance')
            || $this->isDirty('threshold_credits')
            || $this->isDirty('method')
            || $this->isDirty('trigger')
        )) {
            return false;
        }

        if (! ($this->isTarget() && $this->isThreshold())) {
            return false;
        }

        if ($this->target_ongoing_balance === null || $this->threshold_credits === null) {
            return false;
        }

        return bccomp((string) $this->target_ongoing_balance, (string) $this->threshold_credits, 5) === -1;
    }

    // -- Internals ---------------------------------------------------------------

    /** Rails: `compute_fixed_top_up_amount` — private. */
    private function computeFixedTopUpAmount(string|int|float $ongoingBalance, string|int|float $pendingCredits): string
    {
        $paidCredits = (string) $this->paid_credits;

        if (bccomp($paidCredits, '0', 5) === 0 || $this->threshold_credits === null) {
            return $paidCredits;
        }

        // gap = threshold_credits - ongoing_balance - granted_credits - pending_credits
        $gap = bcsub(
            bcsub(
                bcsub((string) $this->threshold_credits, (string) $ongoingBalance, 5),
                (string) $this->granted_credits,
                5,
            ),
            (string) $pendingCredits,
            5,
        );

        // return paid_credits if gap < paid_credits
        if (bccomp($gap, $paidCredits, 5) === -1) {
            return $paidCredits;
        }

        // paid_credits * ((gap / paid_credits).floor + 1)
        // Both operands positive here, so truncating division is the floor.
        $multiple = bcadd(bcdiv($gap, $paidCredits, 0), '1', 0);

        return $this->applyMaxTopUpLimits(bcmul($paidCredits, $multiple, 5));
    }

    /** Rails: `compute_target_top_up_amount` — private. */
    private function computeTargetTopUpAmount(string|int|float $ongoingBalance): string
    {
        $target = (string) $this->target_ongoing_balance;

        if (bccomp((string) $ongoingBalance, $target, 5) >= 0) {
            return '0.0';
        }

        $gap = bcsub($target, (string) $ongoingBalance, 5);

        // NOTE: granted top-ups skip the paid_top_up_min limit since no payment occurs
        if ((bool) $this->grants_target_top_up) {
            return MoneyMath::toF($gap);
        }

        // NOTE: in case of target rule, we don't apply max because reaching
        // target balance is the most important
        return MoneyMath::toF($this->applyMinTopUpLimits($gap));
    }
}
