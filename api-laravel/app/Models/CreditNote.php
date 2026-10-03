<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use App\Support\MoneyMath;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Models\Casts\BcNumeric;
use App\Models\Concerns\Sequenced;
use App\Enums\CreditNoteCreditStatus;
use App\Enums\CreditNoteRefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Port of Rails' CreditNote (app/models/credit_note.rb) for the credit
 * notes slice.
 *
 * Not ported (TODO(port)): attachments (file/xml_file — ActiveStorage),
 * PaperTrail trace, Ransack search, metadata (Metadata::ItemMetadata),
 * refunds, invoice settlements (offsets), integration resources, error
 * details, activity logs.
 */
#[Fillable([
    'customer_id',
    'invoice_id',
    'sequential_id',
    'number',
    'credit_amount_cents',
    'credit_amount_currency',
    'credit_status',
    'balance_amount_cents',
    'balance_amount_currency',
    'reason',
    'file',
    'total_amount_cents',
    'total_amount_currency',
    'refund_amount_cents',
    'refund_amount_currency',
    'refund_status',
    'voided_at',
    'description',
    'taxes_amount_cents',
    'refunded_at',
    'issuing_date',
    'status',
    'coupons_adjustment_amount_cents',
    'precise_coupons_adjustment_amount_cents',
    'precise_taxes_amount_cents',
    'taxes_rate',
    'organization_id',
    'xml_file',
    'offset_amount_cents',
    'offset_amount_currency',
])]
#[Table(name: 'credit_notes')]
class CreditNote extends BaseModel
{
    use HasFactory;
    use Sequenced;

    /** Rails: CreditNote::DB_PRECISION_SCALE. */
    public const DB_PRECISION_SCALE = 5;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Port of `has_one :billing_entity, through: :invoice`. */
    public function billingEntity(): HasOneThrough
    {
        return $this->hasOneThrough(
            BillingEntity::class,
            Invoice::class,
            'id',
            'id',
            'invoice_id',
            'billing_entity_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    /** Port of `has_many :applied_taxes, class_name: "CreditNote::AppliedTax"` (credit_notes_taxes). */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(CreditNoteAppliedTax::class);
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'credit_notes_taxes', 'credit_note_id', 'tax_id');
    }

    /** Port of `has_many :fees, through: :items`. */
    public function fees(): BelongsToMany
    {
        return $this->belongsToMany(Fee::class, 'credit_note_items', 'credit_note_id', 'fee_id');
    }

    // -- Enum accessors --------------------------------------------------------

    public function statusEnum(): ?CreditNoteStatus
    {
        return $this->status instanceof CreditNoteStatus ? $this->status : ($this->status === null ? null : CreditNoteStatus::tryFrom((int) $this->status));
    }

    public function reasonEnum(): ?CreditNoteReason
    {
        return $this->reason instanceof CreditNoteReason ? $this->reason : ($this->reason === null ? null : CreditNoteReason::tryFrom((int) $this->reason));
    }

    public function creditStatusEnum(): ?CreditNoteCreditStatus
    {
        return $this->credit_status instanceof CreditNoteCreditStatus ? $this->credit_status : ($this->credit_status === null ? null : CreditNoteCreditStatus::tryFrom((int) $this->credit_status));
    }

    public function refundStatusEnum(): ?CreditNoteRefundStatus
    {
        return $this->refund_status instanceof CreditNoteRefundStatus ? $this->refund_status : ($this->refund_status === null ? null : CreditNoteRefundStatus::tryFrom((int) $this->refund_status));
    }

    // -- Status predicates (Rails enum predicates) -----------------------------

    public function isDraft(): bool
    {
        return $this->statusEnum() === CreditNoteStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->statusEnum() === CreditNoteStatus::Finalized;
    }

    public function isDeleted(): bool
    {
        return $this->statusEnum() === CreditNoteStatus::Deleted;
    }

    public function isVoided(): bool
    {
        return $this->creditStatusEnum() === CreditNoteCreditStatus::Voided;
    }

    // -- Rails domain methods ---------------------------------------------------

    /** Port of `delegate :purchase_order_number, to: :invoice`. */
    public function purchaseOrderNumber(): ?string
    {
        return $this->invoice?->purchase_order_number;
    }

    /** Port of `file_url` — ActiveStorage is not ported (TODO(port)). */
    public function fileUrl(): ?string
    {
        return null;
    }

    /** Port of `xml_url` — ActiveStorage is not ported (TODO(port)). */
    public function xmlUrl(): ?string
    {
        return null;
    }

    /** Port of `currency` — total_amount_currency. */
    public function currency(): string
    {
        return (string) $this->total_amount_currency;
    }

    public function credited(): bool
    {
        return (int) $this->credit_amount_cents > 0;
    }

    public function refunded(): bool
    {
        return (int) $this->refund_amount_cents > 0;
    }

    public function hasOffset(): bool
    {
        return (int) $this->offset_amount_cents > 0;
    }

    /** Port of `subscription_ids` — fees.pluck(:subscription_id).uniq. */
    public function subscriptionIds(): array
    {
        return $this->fees()
            ->whereNotNull('fees.subscription_id')
            ->pluck('fees.subscription_id')
            ->unique()
            ->values()
            ->all();
    }

    /** Port of `voidable?` — not already voided and a credit balance remains. */
    public function voidable(): bool
    {
        if ($this->isVoided()) {
            return false;
        }

        return (int) $this->balance_amount_cents > 0;
    }

    /** Port of `mark_as_voided!`. */
    public function markAsVoided(?DateTimeInterface $timestamp = null): bool
    {
        return $this->update([
            'credit_status' => CreditNoteCreditStatus::Voided->value,
            'voided_at' => $timestamp ?? now(),
            'balance_amount_cents' => 0,
        ]);
    }

    /** Port of `sub_total_including_taxes_amount_cents`. */
    public function subTotalIncludingTaxesAmountCents(): int
    {
        return MoneyMath::round(MoneyMath::add(
            (string) $this->subTotalExcludingTaxesAmountCents(),
            (string) $this->precise_taxes_amount_cents,
        ));
    }

    /**
     * The precise decimal attributes as decimal strings — an unsaved model
     * carries no value yet, which Rails would default to 0.
     */
    public function preciseCouponsAdjustment(): string
    {
        return (string) ($this->precise_coupons_adjustment_amount_cents ?? '0');
    }

    public function preciseTaxesAmount(): string
    {
        return (string) ($this->precise_taxes_amount_cents ?? '0');
    }

    /** Port of `sub_total_excluding_taxes_amount_cents` (rounds the precise sum). */
    public function subTotalExcludingTaxesAmountCents(): int
    {
        $itemsPrecise = (string) $this->items->sum(
            fn (CreditNoteItem $item): string => (string) $item->precise_amount_cents,
        );

        return MoneyMath::round(MoneyMath::sub($itemsPrecise, $this->preciseCouponsAdjustment()));
    }

    /** Port of `precise_total`. */
    public function preciseTotal(): string
    {
        $itemsPrecise = (string) $this->items->sum(
            fn (CreditNoteItem $item): string => (string) $item->precise_amount_cents,
        );

        return MoneyMath::sub(
            MoneyMath::add($itemsPrecise, $this->preciseTaxesAmount()),
            $this->preciseCouponsAdjustment(),
        );
    }

    /** Port of `taxes_rounding_adjustment` — a decimal (no rounding). */
    public function taxesRoundingAdjustment(): string
    {
        return MoneyMath::sub(
            (string) ((int) $this->taxes_amount_cents),
            $this->preciseTaxesAmount(),
        );
    }

    /** Port of `rounding_adjustment`. */
    public function roundingAdjustment(): int
    {
        return MoneyMath::round(MoneyMath::sub(
            (string) ((int) $this->total_amount_cents),
            $this->preciseTotal(),
        ));
    }

    /** Port of `for_credit_invoice?`. */
    public function forCreditInvoice(): bool
    {
        return $this->invoice->typeEnum() === \App\Enums\InvoiceType::Credit;
    }

    /** Port of `ensure_number` — "<invoice number>-CN<%03d sequential_id>". */
    public function ensureNumber(): void
    {
        if ($this->number !== null && ! $this->statusChangedToFinalized()) {
            return;
        }

        // NOTE: Rails runs Sequenced's before_save before the model's own
        // hook; the port's saving-hook order is not guaranteed, so make the
        // sequential id available explicitly (no-op when already assigned).
        $this->sequential_id ??= $this->generateSequentialId();

        $formattedSequentialId = sprintf('%03d', (int) $this->sequential_id);

        $this->number = $this->invoice->number.'-CN'.$formattedSequentialId;
    }

    /**
     * Port of `status_changed_to_finalized?` — the draft → finalized
     * transition (the only one Rails enumerates).
     */
    public function statusChangedToFinalized(): bool
    {
        if ($this->statusEnum() !== CreditNoteStatus::Finalized) {
            return false;
        }

        $original = $this->getOriginal('status');

        if ($original === null) {
            return false;
        }

        $originalEnum = $original instanceof CreditNoteStatus
            ? $original
            : CreditNoteStatus::tryFrom((int) $original);

        return $originalEnum === CreditNoteStatus::Draft;
    }

    // -- Rails before_save hooks ------------------------------------------------

    #[Boot]
    protected static function bootCreditNote(): void
    {
        static::saving(function (self $creditNote): void {
            $creditNote->ensureNumber();
        });
    }

    // -- Scopes (Rails enum scopes) --------------------------------------------

    protected function scopeFinalized(Builder $query): Builder
    {
        return $query->where('status', CreditNoteStatus::Finalized->value);
    }

    protected function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', CreditNoteStatus::Draft->value);
    }

    /**
     * Port of: sequenced scope: ->(credit_note) { CreditNote.where(invoice_id: credit_note.invoice_id) },
     *          lock_key: ->(credit_note) { credit_note.invoice_id }
     */
    protected function sequenceScope(): Builder
    {
        return static::query()->where('invoice_id', $this->invoice_id);
    }

    protected function sequencedLockKey(): ?string
    {
        return (string) $this->invoice_id;
    }

    protected function casts(): array
    {
        return [
            'sequential_id' => 'integer',
            'credit_amount_cents' => 'integer',
            'credit_status' => CreditNoteCreditStatus::class,
            'balance_amount_cents' => 'integer',
            'reason' => CreditNoteReason::class,
            'total_amount_cents' => 'integer',
            'refund_amount_cents' => 'integer',
            'refund_status' => CreditNoteRefundStatus::class,
            'voided_at' => 'datetime',
            'taxes_amount_cents' => 'integer',
            'refunded_at' => 'datetime',
            'issuing_date' => 'date:Y-m-d',
            'status' => CreditNoteStatus::class,
            'coupons_adjustment_amount_cents' => 'integer',
            'precise_coupons_adjustment_amount_cents' => [BcNumeric::class, 'scale' => 5],
            'precise_taxes_amount_cents' => [BcNumeric::class, 'scale' => 5],
            'taxes_rate' => 'float',
            'offset_amount_cents' => 'integer',
        ];
    }
}
