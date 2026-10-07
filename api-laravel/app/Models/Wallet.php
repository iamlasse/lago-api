<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletStatus;
use App\Models\Casts\BcNumeric;
use App\Models\Casts\PostgresArray;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\OptimisticLocking;
use App\Models\Concerns\ConnectionResolvable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Wallet (app/models/wallet.rb) for the prepaid-credits
 * domain.
 *
 * Not ported (TODO(port)): PaperTrail trace, HasPurchaseOrderNumber
 * activity hooks (the plain string column is ported), recurring transaction
 * rules relations (RecurringTransactionRule has no model yet), invoice
 * custom sections, UsageMonitoring alerts, Clickhouse activity logs,
 * PaymentMethod (payment_method_id / payment_method_type are carried as
 * plain columns until the payment-methods slice lands).
 */
#[Table(name: 'wallets')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'customer_id',
    'status',
    'name',
    'rate_amount',
    'credits_balance',
    'consumed_credits',
    'expiration_at',
    'last_balance_sync_at',
    'last_consumed_credit_at',
    'terminated_at',
    'balance_cents',
    'balance_currency',
    'consumed_amount_cents',
    'consumed_amount_currency',
    'ongoing_balance_cents',
    'ongoing_usage_balance_cents',
    'credits_ongoing_balance',
    'credits_ongoing_usage_balance',
    'depleted_ongoing_balance',
    'invoice_requires_successful_payment',
    'lock_version',
    'ready_to_be_refreshed',
    'organization_id',
    'allowed_fee_types',
    'last_ongoing_balance_sync_at',
    'priority',
    'paid_top_up_min_amount_cents',
    'paid_top_up_max_amount_cents',
    'payment_method_id',
    'payment_method_type',
    'skip_invoice_custom_sections',
    'traceable',
    'code',
    'billing_entity_id',
    'purchase_order_number',
])]
class Wallet extends BaseModel
{
    use ConnectionResolvable;
    use HasFactory;
    use OptimisticLocking;

    /** Rails: LOWEST_PRIORITY. */
    public const LOWEST_PRIORITY = 50;

    /** Rails: REFRESH_RELEVANT_ATTRIBUTES — changes here re-enqueue the ongoing-balance refresh. */
    public const REFRESH_RELEVANT_ATTRIBUTES = ['code', 'priority', 'allowed_fee_types'];

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so the NOT NULL DEFAULT columns are
     * declared here to keep reads (and the serializers) identical to Rails.
     */
    protected $attributes = [
        'rate_amount' => '0',
        'credits_balance' => '0',
        'consumed_credits' => '0',
        'balance_cents' => 0,
        'consumed_amount_cents' => 0,
        'ongoing_balance_cents' => 0,
        'ongoing_usage_balance_cents' => 0,
        'credits_ongoing_balance' => '0',
        'credits_ongoing_usage_balance' => '0',
        'depleted_ongoing_balance' => false,
        'invoice_requires_successful_payment' => false,
        'lock_version' => 0,
        'ready_to_be_refreshed' => false,
        // Raw Postgres array literal — the $attributes defaults bypass the
        // PostgresArray cast's setter.
        'allowed_fee_types' => '{}',
        'priority' => self::LOWEST_PRIORITY,
        'payment_method_type' => 'provider',
        'skip_invoice_custom_sections' => false,
        'traceable' => false,
    ];

    // -- Rails scopes ------------------------------------------------------------

    /** Rails: `self.in_application_order` — order(:priority, :created_at). */
    public static function inApplicationOrder(): Builder
    {
        return static::query()->orderBy('priority')->oldest();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // TODO(port): belongs_to :payment_method — no PaymentMethod model yet.

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    // -- Usage monitoring (usage-monitoring slice, appended) ---------------------

    /** Rails: has_many :alerts, class_name: "UsageMonitoring::Alert". */
    public function alerts(): HasMany
    {
        return $this->hasMany(UsageMonitoring\Alert::class);
    }

    /** Rails: has_many :triggered_alerts, -> { triggered }. */
    public function triggeredAlerts(): HasMany
    {
        return $this->hasMany(UsageMonitoring\TriggeredAlert::class)->where('kind', 'triggered');
    }

    // -- Relations (continued) ---------------------------------------------------

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function walletTargets(): HasMany
    {
        return $this->hasMany(WalletTarget::class);
    }

    public function billableMetrics(): BelongsToMany
    {
        return $this->belongsToMany(BillableMetric::class, 'wallet_targets', 'wallet_id', 'billable_metric_id');
    }

    public function billingObjectConnections(): MorphMany
    {
        return $this->morphMany(BillingObjectConnection::class, 'owner');
    }

    /**
     * Rails: `has_one :metadata, class_name: "Metadata::ItemMetadata",
     * as: :owner`. owner_type stores the Rails class name, so the relation
     * is constrained explicitly (same convention as
     * billingObjectConnections).
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(ItemMetadata::class, 'owner_id')
            ->where('owner_type', $this->railsName());
    }

    /**
     * Rails: `def billing_entity; super || customer&.billing_entity; end` —
     * the explicit override or the customer's default. Note: this helper
     * shadows nothing; Eloquent's `billingEntity` relation stays nullable,
     * call sites needing the fallback use this method.
     */
    public function resolvedBillingEntity(): ?BillingEntity
    {
        return $this->billingEntity ?? $this->customer?->billingEntity;
    }

    // -- Rails enum / status helpers -------------------------------------------

    public function statusEnum(): ?WalletStatus
    {
        if ($this->status === null) {
            return null;
        }

        return $this->status instanceof WalletStatus
            ? $this->status
            : WalletStatus::tryFrom((int) $this->status);
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === WalletStatus::Active;
    }

    public function isTerminated(): bool
    {
        return $this->statusEnum() === WalletStatus::Terminated;
    }

    /** Rails: `def mark_as_terminated!(timestamp = Time.zone.now)`. */
    public function markAsTerminated(mixed $timestamp = null): void
    {
        $this->terminated_at ??= $timestamp ?? now();

        $this->status = WalletStatus::Terminated->value;

        $this->save();
    }

    /**
     * Rails: `currency_for_balance` (money-rails) — the Currency record of
     * the wallet's balance; only subunit_to_unit / exponent are needed for
     * billing math.
     *
     * @return object{subunit_to_unit: int, exponent: int}
     */
    public function currencyForBalance(): object
    {
        $currency = $this->balance_currency ?? 'EUR';

        return new class((string) $currency)
        {
            public function __construct(private readonly string $code) {}

            public int $subunit_to_unit {
                get => \App\Support\Currency::subunitToUnit($this->code);
            }

            public int $exponent {
                get => \App\Support\Currency::exponent($this->code);
            }
        };
    }

    // -- Rails helpers -----------------------------------------------------------

    /** Rails: `limited_fee_types?` */
    public function limitedFeeTypes(): bool
    {
        return ($this->allowed_fee_types ?? []) !== [];
    }

    /** Rails: `limited_to_billable_metrics?` */
    public function limitedToBillableMetrics(): bool
    {
        return $this->billableMetrics()->exists();
    }

    // -- Validation --------------------------------------------------------------

    /**
     * Port of the model validations the services rely on — a
     * field => [codes] hash, empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // validates :rate_amount, numericality: {greater_than: 0}
        if ($this->rate_amount === null || bccomp((string) $this->rate_amount, '0', 5) !== 1) {
            $errors['rate_amount'] = ['must_be_greater_than_zero'];
        }

        if (! Currencies::valid($this->balance_currency)) {
            $errors['balance_currency'] = ['not_a_valid_currency_code'];
        }

        if ($this->invoice_requires_successful_payment === null) {
            $errors['invoice_requires_successful_payment'] = ['blank'];
        }

        if ($this->paid_top_up_min_amount_cents !== null && $this->paid_top_up_min_amount_cents <= 0) {
            $errors['paid_top_up_min_amount_cents'] = ['must_be_greater_than_zero'];
        }

        if ($this->paid_top_up_max_amount_cents !== null && $this->paid_top_up_max_amount_cents <= 0) {
            $errors['paid_top_up_max_amount_cents'] = ['must_be_greater_than_zero'];
        }

        if ($this->priority === null || $this->priority < 1 || $this->priority > self::LOWEST_PRIORITY) {
            $errors['priority'] = ['not_included_in_list'];
        }

        if ((bool) $this->traceable && (int) $this->balance_cents < 0) {
            $errors['balance_cents'] = ['must_be_greater_than_or_equal_to_zero'];
        }

        if (
            $this->paid_top_up_min_amount_cents !== null
            && $this->paid_top_up_max_amount_cents !== null
            && $this->paid_top_up_max_amount_cents < $this->paid_top_up_min_amount_cents
        ) {
            $errors['paid_top_up_max_amount_cents'] = ['must_be_greater_than_or_equal_min'];
        }

        if ($this->codeChanged() && $this->uniqueCodeViolation()) {
            $errors['code'] = ['value_already_exist'];
        }

        return $errors;
    }

    // -- Currency virtual attribute (Rails: currency= / currency) ---------------

    protected function currency(): Attribute
    {
        return Attribute::make(
            fn (): ?string => $this->balance_currency,
            function (mixed $currency): array {
                if ($currency === null) {
                    return [];
                }

                // Rails: currency= writes both currency columns; the virtual
                // attribute itself is not stored.
                return [
                    'balance_currency' => $currency,
                    'consumed_amount_currency' => $currency,
                ];
            },
        );
    }

    /** Rails: `scope :expired`. */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->where('expiration_at', '<=', now());
    }

    /** Rails: `scope :with_positive_balance`. */
    #[Scope]
    protected function withPositiveBalance(Builder $query): Builder
    {
        return $query->where('balance_cents', '>', 0);
    }

    /** Rails: `scope :ready_to_be_refreshed`. */
    #[Scope]
    protected function readyToBeRefreshed(Builder $query): Builder
    {
        return $query->where('ready_to_be_refreshed', true);
    }

    /** Rails: `scope :active` — `enum :status` generates a plural-name scope per value. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', WalletStatus::Active->value);
    }

    /** Rails: `scope :terminated` (enum-generated). */
    #[Scope]
    protected function terminated(Builder $query): Builder
    {
        return $query->where('status', WalletStatus::Terminated->value);
    }

    protected function codeChanged(): bool
    {
        return $this->exists
            ? $this->isDirty('code')
            : $this->code !== null;
    }

    /** Rails: `unique_code_per_customer` — one active wallet per (customer, code). */
    protected function uniqueCodeViolation(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if (($this->code ?? '') === '') {
            return false;
        }

        $query = static::query()
            ->where('customer_id', $this->customer_id)
            ->where('code', $this->code)
            ->where('status', WalletStatus::Active->value);

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        return $query->exists();
    }

    protected function casts(): array
    {
        return [
            'status' => WalletStatus::class,
            'rate_amount' => [BcNumeric::class, 'scale' => 5],
            'credits_balance' => [BcNumeric::class, 'scale' => 5],
            'consumed_credits' => [BcNumeric::class, 'scale' => 5],
            'expiration_at' => 'datetime',
            'last_balance_sync_at' => 'datetime',
            'last_consumed_credit_at' => 'datetime',
            'terminated_at' => 'datetime',
            'balance_cents' => 'integer',
            'consumed_amount_cents' => 'integer',
            'ongoing_balance_cents' => 'integer',
            'ongoing_usage_balance_cents' => 'integer',
            'credits_ongoing_balance' => [BcNumeric::class, 'scale' => 5],
            'credits_ongoing_usage_balance' => [BcNumeric::class, 'scale' => 5],
            'depleted_ongoing_balance' => 'boolean',
            'invoice_requires_successful_payment' => 'boolean',
            'lock_version' => 'integer',
            'ready_to_be_refreshed' => 'boolean',
            'allowed_fee_types' => PostgresArray::class,
            'last_ongoing_balance_sync_at' => 'datetime',
            'priority' => 'integer',
            'paid_top_up_min_amount_cents' => 'integer',
            'paid_top_up_max_amount_cents' => 'integer',
            'skip_invoice_custom_sections' => 'boolean',
            'traceable' => 'boolean',
        ];
    }
    /**
     * Rails: `has_many :applied_invoice_custom_sections,
     * class_name: "Wallet::AppliedInvoiceCustomSection", dependent: :destroy`
     * (the `wallets_invoice_custom_sections` table).
     */
    public function appliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(WalletAppliedInvoiceCustomSection::class, 'wallet_id');
    }

    /** Rails: `has_many :selected_invoice_custom_sections, through: :applied_invoice_custom_sections, source: :invoice_custom_section`. */
    public function selectedInvoiceCustomSections(): BelongsToMany
    {
        return $this->belongsToMany(
            InvoiceCustomSection::class,
            'wallets_invoice_custom_sections',
            'wallet_id',
            'invoice_custom_section_id',
        )->whereNull('invoice_custom_sections.deleted_at');
    }

}
