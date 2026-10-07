<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MoneyMath;
use App\Models\Casts\BcNumeric;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionSource;
use App\Enums\WalletTransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\OptimisticLocking;
use App\Enums\WalletTransactionCreditStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' WalletTransaction (app/models/wallet_transaction.rb) — a
 * line of the wallet's prepaid-credit ledger.
 *
 * Not ported (TODO(port)): PaperTrail trace, invoice custom sections,
 * PaymentMethod (payment_method_id / payment_method_type stay plain
 * columns until the payment-methods slice lands).
 */
#[Table(name: 'wallet_transactions')]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'wallet_id',
    'transaction_type',
    'status',
    'amount',
    'credit_amount',
    'settled_at',
    'invoice_id',
    'source',
    'transaction_status',
    'invoice_requires_successful_payment',
    'metadata',
    'credit_note_id',
    'failed_at',
    'organization_id',
    'lock_version',
    'priority',
    'name',
    'payment_method_id',
    'payment_method_type',
    'skip_invoice_custom_sections',
    'remaining_amount_cents',
    'voided_invoice_id',
    'billing_entity_id',
    'purchase_order_number',
])]
class WalletTransaction extends BaseModel
{
    use HasFactory;
    use OptimisticLocking;

    /** Rails: WalletTransaction::LOWEST_PRIORITY — priority default mirrors Wallet's. */
    public const LOWEST_PRIORITY = 50;

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so the NOT NULL DEFAULT columns are
     * declared here to keep reads (and the serializers) identical to Rails.
     */
    protected $attributes = [
        'amount' => '0',
        'credit_amount' => '0',
        'invoice_requires_successful_payment' => false,
        // Raw jsonb literal — the $attributes default bypasses the cast's
        // setter.
        'metadata' => '[]',
        'lock_version' => 0,
        'priority' => self::LOWEST_PRIORITY,
        'source' => 0,
        'transaction_status' => 0,
        'payment_method_type' => 'provider',
        'skip_invoice_custom_sections' => false,
    ];

    /**
     * Rails: `def self.order_by_priority` — priority, then the fixed
     * transaction_status order (granted, purchased, voided, invoiced), then
     * created_at.
     */
    public static function orderByPriority(): Builder
    {
        $statuses = [
            WalletTransactionCreditStatus::Granted->value,
            WalletTransactionCreditStatus::Purchased->value,
            WalletTransactionCreditStatus::Voided->value,
            WalletTransactionCreditStatus::Invoiced->value,
        ];

        $binds = implode(',', array_fill(0, count($statuses), '?'));

        return static::query()
            ->orderBy('priority')
            ->orderByRaw("array_position(array[{$binds}], transaction_status)", $statuses)->oldest();
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    /** These relations are populated only for outbound transactions. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** Populated for inbound transactions created when an invoice is voided. */
    public function voidedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'voided_invoice_id');
    }

    /** Rails: `delegate :customer, to: :wallet`. */
    public function customer(): ?Customer
    {
        return $this->wallet->customer;
    }

    // -- Rails enum accessors ----------------------------------------------------

    public function statusEnum(): ?WalletTransactionStatus
    {
        if ($this->status === null) {
            return null;
        }

        return $this->status instanceof WalletTransactionStatus
            ? $this->status
            : WalletTransactionStatus::tryFrom((int) $this->status);
    }

    public function transactionStatusEnum(): ?WalletTransactionCreditStatus
    {
        if ($this->transaction_status === null) {
            return null;
        }

        return $this->transaction_status instanceof WalletTransactionCreditStatus
            ? $this->transaction_status
            : WalletTransactionCreditStatus::tryFrom((int) $this->transaction_status);
    }

    public function transactionTypeEnum(): ?WalletTransactionType
    {
        if ($this->transaction_type === null) {
            return null;
        }

        return $this->transaction_type instanceof WalletTransactionType
            ? $this->transaction_type
            : WalletTransactionType::tryFrom((int) $this->transaction_type);
    }

    public function sourceEnum(): ?WalletTransactionSource
    {
        if ($this->source === null) {
            return null;
        }

        return $this->source instanceof WalletTransactionSource
            ? $this->source
            : WalletTransactionSource::tryFrom((int) $this->source);
    }

    public function isPending(): bool
    {
        return $this->statusEnum() === WalletTransactionStatus::Pending;
    }

    public function isSettled(): bool
    {
        return $this->statusEnum() === WalletTransactionStatus::Settled;
    }

    public function isFailed(): bool
    {
        return $this->statusEnum() === WalletTransactionStatus::Failed;
    }

    public function isInbound(): bool
    {
        return $this->transactionTypeEnum() === WalletTransactionType::Inbound;
    }

    public function isOutbound(): bool
    {
        return $this->transactionTypeEnum() === WalletTransactionType::Outbound;
    }

    // -- Rails instance helpers ---------------------------------------------------

    /** Rails: `amount_cents` — amount * subunit_to_unit. */
    public function amountCents(): int
    {
        return MoneyMath::round(
            MoneyMath::mul((string) $this->amount, (string) $this->wallet->currencyForBalance()->subunit_to_unit)
        );
    }

    /** Rails: `unit_amount_cents`. */
    public function unitAmountCents(): int
    {
        return MoneyMath::round(
            MoneyMath::mul((string) $this->wallet->rate_amount, (string) $this->wallet->currencyForBalance()->subunit_to_unit)
        );
    }

    /** Rails: `remaining_credit_amount`. */
    public function remainingCreditAmount(): ?string
    {
        if ($this->remaining_amount_cents === null) {
            return null;
        }

        $wallet = $this->wallet;

        return MoneyMath::fdiv(
            MoneyMath::fdiv((string) $this->remaining_amount_cents, (string) $wallet->currencyForBalance()->subunit_to_unit),
            (string) $wallet->rate_amount,
        );
    }

    /** Rails: `resolved_purchase_order_number`. */
    public function resolvedPurchaseOrderNumber(): ?string
    {
        return ($this->purchase_order_number ?? '') !== ''
            ? $this->purchase_order_number
            : $this->wallet->purchase_order_number;
    }

    /**
     * Rails: `invoice_custom_section_resource` — the resource that should
     * drive invoice custom sections for this transaction. Priority chain:
     * transaction first, then wallet.
     */
    public function invoiceCustomSectionResource(): self|Wallet
    {
        if (! $this->skip_invoice_custom_sections && $this->selectedInvoiceCustomSections()->exists()) {
            return $this;
        }

        $wallet = $this->wallet;

        if (! $wallet->skip_invoice_custom_sections && $wallet->selectedInvoiceCustomSections()->exists()) {
            return $wallet;
        }

        return $this;
    }

    /** Rails: `def mark_as_failed!(timestamp = Time.zone.now)`. */
    public function markAsFailed(mixed $timestamp = null): void
    {
        if ($this->isFailed()) {
            return;
        }

        $this->status = WalletTransactionStatus::Failed->value;
        $this->failed_at = $timestamp ?? now();

        $this->save();
    }

    // -- Validation ------------------------------------------------------------

    /**
     * Port of the model validations the services rely on — a
     * field => [codes] hash, empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        foreach (['status', 'transaction_type', 'source', 'transaction_status'] as $attribute) {
            if ($this->getAttribute($attribute) === null) {
                $errors[$attribute] = ['blank'];
            }
        }

        if ($this->priority === null) {
            $errors['priority'] = ['blank'];
        } elseif ($this->priority < 1 || $this->priority > 50) {
            $errors['priority'] = ['not_included_in_list'];
        }

        if ($this->name !== null && (mb_strlen($this->name) < 1 || mb_strlen($this->name) > 255)) {
            $errors['name'] = ['invalid_length'];
        }

        if ($this->invoice_requires_successful_payment === null) {
            $errors['invoice_requires_successful_payment'] = ['blank'];
        }

        if ($this->isInbound()) {
            if ($this->remaining_amount_cents !== null && $this->remaining_amount_cents < 0) {
                $errors['remaining_amount_cents'] = ['must_be_greater_than_or_equal_to_zero'];
            }
        } elseif ($this->remaining_amount_cents !== null) {
            $errors['remaining_amount_cents'] = ['must_be_blank'];
        }

        return $errors;
    }

    // -- Rails scopes ---------------------------------------------------------------

    /** Rails: `scope :pending`. */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', WalletTransactionStatus::Pending->value);
    }

    /** Rails: `scope :available_inbound` — inbound.settled.remaining > 0. */
    #[Scope]
    protected function availableInbound(Builder $query): Builder
    {
        return $query
            ->where('transaction_type', WalletTransactionType::Inbound->value)
            ->where('status', WalletTransactionStatus::Settled->value)
            ->where('remaining_amount_cents', '>', 0);
    }

    /**
     * Rails: `scope :in_consumption_order` — priority, granted-first,
     * created_at.
     */
    #[Scope]
    protected function inConsumptionOrder(Builder $query): Builder
    {
        $granted = WalletTransactionCreditStatus::Granted->value;

        return $query
            ->orderBy('priority')
            ->orderByRaw("CASE WHEN transaction_status = {$granted} THEN 0 ELSE 1 END")->oldest();
    }

    /** Rails: `scope :inbound` (enum-generated). */
    #[Scope]
    protected function inbound(Builder $query): Builder
    {
        return $query->where('transaction_type', WalletTransactionType::Inbound->value);
    }

    protected function casts(): array
    {
        return [
            'transaction_type' => WalletTransactionType::class,
            'status' => WalletTransactionStatus::class,
            'amount' => [BcNumeric::class, 'scale' => 5],
            'credit_amount' => [BcNumeric::class, 'scale' => 5],
            'settled_at' => 'datetime',
            'source' => WalletTransactionSource::class,
            'transaction_status' => WalletTransactionCreditStatus::class,
            'invoice_requires_successful_payment' => 'boolean',
            'metadata' => 'array',
            'failed_at' => 'datetime',
            'lock_version' => 'integer',
            'priority' => 'integer',
            'skip_invoice_custom_sections' => 'boolean',
            'remaining_amount_cents' => 'integer',
        ];
    }
    /**
     * Rails: `has_many :applied_invoice_custom_sections,
     * class_name: "WalletTransaction::AppliedInvoiceCustomSection", dependent: :destroy`
     * (the `wallet_transactions_invoice_custom_sections` table).
     */
    public function appliedInvoiceCustomSections(): HasMany
    {
        return $this->hasMany(WalletTransactionAppliedInvoiceCustomSection::class, 'wallet_transaction_id');
    }

    /** Rails: `has_many :selected_invoice_custom_sections, through: :applied_invoice_custom_sections, source: :invoice_custom_section`. */
    public function selectedInvoiceCustomSections(): BelongsToMany
    {
        return $this->belongsToMany(
            InvoiceCustomSection::class,
            'wallet_transactions_invoice_custom_sections',
            'wallet_transaction_id',
            'invoice_custom_section_id',
        )->whereNull('invoice_custom_sections.deleted_at');
    }

}
