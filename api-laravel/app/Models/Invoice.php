<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxStatus;
use App\Models\Concerns\Sequenced;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Invoice (app/models/invoice.rb) for the billing pipeline.
 *
 * Not ported (TODO(port)): attachments (file/xml_file), PaperTrail trace,
 * Ransack search, credit-note offsets precalculation, payment requests,
 * usage thresholds, invoice custom sections, error details, activity logs.
 */
#[Fillable([
    'issuing_date',
    'taxes_amount_cents',
    'total_amount_cents',
    'invoice_type',
    'payment_status',
    'number',
    'sequential_id',
    'file',
    'customer_id',
    'taxes_rate',
    'status',
    'timezone',
    'payment_attempts',
    'ready_for_payment_processing',
    'organization_id',
    'version_number',
    'currency',
    'fees_amount_cents',
    'coupons_amount_cents',
    'credit_notes_amount_cents',
    'prepaid_credit_amount_cents',
    'sub_total_excluding_taxes_amount_cents',
    'sub_total_including_taxes_amount_cents',
    'payment_due_date',
    'net_payment_term',
    'voided_at',
    'organization_sequential_id',
    'ready_to_be_refreshed',
    'payment_dispute_lost_at',
    'skip_charges',
    'payment_overdue',
    'negative_amount_cents',
    'progressive_billing_credit_amount_cents',
    'tax_status',
    'total_paid_amount_cents',
    'self_billed',
    'applied_grace_period',
    'billing_entity_id',
    'billing_entity_sequential_id',
    'finalized_at',
    'voided_invoice_id',
    'xml_file',
    'expected_finalization_date',
    'prepaid_granted_credit_amount_cents',
    'prepaid_purchased_credit_amount_cents',
    'payment_method_id',
    'skip_automatic_payment',
    'purchase_order_number',
    'payment_term',
    'payment_term_source',
    'search_terms',
])]
#[Table(name: 'invoices')]
class Invoice extends BaseModel
{
    use HasFactory;
    use Sequenced;

    /**
     * Port of Invoice::GENERATED_INVOICE_STATUSES (string names).
     * NOTE: `open` is deliberately NOT generated — Rails stores names here.
     */
    public const GENERATED_STATUS_NAMES = ['finalized', 'closed'];

    /**
     * Port of the RefreshSearchTermsService update_all — search_terms is a
     * computed concatenation of number, PO number and customer identity.
     */
    public static function searchTermsSql(): string
    {
        return "concat_ws(' ', invoices.number, invoices.purchase_order_number, ".
            "(SELECT concat_ws(' ', c.name, c.firstname, c.lastname, c.legal_name, c.external_id, c.email) ".
            'FROM customers c WHERE c.id = invoices.customer_id))';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    public function invoiceSubscriptions(): HasMany
    {
        return $this->hasMany(InvoiceSubscription::class);
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(
            Subscription::class,
            'invoice_subscriptions',
            'invoice_id',
            'subscription_id'
        );
    }

    /** Port of `has_many :applied_taxes, class_name: "Invoice::AppliedTax"` (invoices_taxes). */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(InvoiceAppliedTax::class);
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'invoices_taxes', 'invoice_id', 'tax_id');
    }

    // -- Status helpers (Rails enum predicates) -------------------------------

    public function statusEnum(): ?InvoiceStatus
    {
        return $this->status instanceof InvoiceStatus ? $this->status : ($this->status === null ? null : InvoiceStatus::tryFrom((int) $this->status));
    }

    public function typeEnum(): ?InvoiceType
    {
        return $this->invoice_type instanceof InvoiceType ? $this->invoice_type : ($this->invoice_type === null ? null : InvoiceType::tryFrom((int) $this->invoice_type));
    }

    public function isDraft(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Finalized;
    }

    public function isGenerating(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Generating;
    }

    public function isVoided(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Voided;
    }

    public function isClosed(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Closed;
    }

    public function isPending(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Pending;
    }

    public function isSubscription(): bool
    {
        return $this->typeEnum() === InvoiceType::Subscription;
    }

    public function isProgressiveBilling(): bool
    {
        return $this->typeEnum() === InvoiceType::ProgressiveBilling;
    }

    /** Rails: `Invoice::GENERATED_INVOICE_STATUSES.include?(invoice.status)`. */
    public function generated(): bool
    {
        $name = $this->statusEnum()?->label();

        return $name !== null && in_array($name, self::GENERATED_STATUS_NAMES, true);
    }

    public function paymentStatusEnum(): ?InvoicePaymentStatus
    {
        if ($this->payment_status === null) {
            return null;
        }

        return $this->payment_status instanceof InvoicePaymentStatus
            ? $this->payment_status
            : InvoicePaymentStatus::tryFrom((int) $this->payment_status);
    }

    public function paymentPending(): bool
    {
        return $this->paymentStatusEnum() === InvoicePaymentStatus::Pending;
    }

    public function paymentSucceeded(): bool
    {
        return $this->paymentStatusEnum() === InvoicePaymentStatus::Succeeded;
    }

    public function taxPending(): bool
    {
        return $this->tax_status === InvoiceTaxStatus::Pending->value;
    }

    public function subscriptionGated(): bool
    {
        return $this->subscriptions->contains(fn (Subscription $s) => $s->gated());
    }

    /**
     * Port of `total_due_amount_cents` (voided invoices are due 0;
     * credit-note offset amounts are not ported yet — TODO(port)).
     */
    public function totalDueAmountCents(): int
    {
        if ($this->isVoided()) {
            return 0;
        }

        return (int) $this->total_amount_cents - (int) $this->total_paid_amount_cents;
    }

    /**
     * Port of `status_changed_to_finalized?` — the from-states Rails
     * enumerates (draft, generating, open, failed, pending) → finalized.
     */
    public function statusChangedToFinalized(): bool
    {
        if ($this->statusEnum() !== InvoiceStatus::Finalized) {
            return false;
        }

        $original = $this->getOriginal('status');

        if ($original === null) {
            return false;
        }

        $originalEnum = $original instanceof InvoiceStatus
            ? $original
            : InvoiceStatus::tryFrom((int) $original);

        return in_array(
            $originalEnum,
            [InvoiceStatus::Draft, InvoiceStatus::Generating, InvoiceStatus::Open, InvoiceStatus::Failed, InvoiceStatus::Pending],
            true,
        );
    }

    /** Port of `ensure_billing_entity_sequential_id`. */
    public function ensureBillingEntitySequentialId(): void
    {
        if ($this->self_billed) {
            return;
        }

        if ($this->billing_entity_sequential_id !== null) {
            return;
        }

        if (! $this->statusChangedToFinalized()) {
            return;
        }

        $this->billing_entity_sequential_id = $this->generateBillingEntitySequentialId();
    }

    /**
     * Port of `generate_billing_entity_sequential_id` — advisory-locked
     * max+1 over the billing entity's generated (finalized/voided),
     * non-self-billed invoices, skipping already-taken ids.
     */
    public function generateBillingEntitySequentialId(): int
    {
        $lockKey = 'billing_entity_sequential_id_'.$this->billing_entity_id;

        $connection = $this->getConnection();

        $connection->statement("SET LOCAL lock_timeout = '10s'");
        $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', [$lockKey]);

        $generated = self::query()
            ->where('billing_entity_id', $this->billing_entity_id)
            ->where('self_billed', false)
            ->whereIn('status', [InvoiceStatus::Finalized->value, InvoiceStatus::Voided->value]);

        $next = (int) ((clone $generated)->max('billing_entity_sequential_id') ?? 0);

        do {
            $next++;
            $taken = (clone $generated)
                ->where('billing_entity_sequential_id', $next)
                ->exists();
        } while ($taken);

        return $next;
    }

    /**
     * Port of `ensure_number` — DRAFT placeholder until finalization, then
     * per-customer or per-billing-entity document numbering.
     */
    public function ensureNumber(): void
    {
        if ($this->number === null && ! $this->statusChangedToFinalized()) {
            $this->number = $this->billingEntity->document_number_prefix.'-DRAFT';
        }

        if (! $this->statusChangedToFinalized()) {
            return;
        }

        $billingEntity = $this->billingEntity;
        $numbering = $billingEntity->document_numbering;
        $numberingValue = $numbering instanceof \App\Enums\EntityDocumentNumbering
            ? $numbering->value
            : (is_string($numbering) ? $numbering : null);
        $perCustomer = $numberingValue === \App\Enums\EntityDocumentNumbering::PerCustomer->value;

        if ($perCustomer || $this->self_billed) {
            // NOTE: Example of expected customer slug format is ORG_PREFIX-005
            $customerSlug = $billingEntity->document_number_prefix.'-'.sprintf('%03d', $this->customer->sequential_id);
            $formattedSequentialId = sprintf('%03d', (int) $this->sequential_id);

            $this->number = $customerSlug.'-'.$formattedSequentialId;
        } else {
            $billingEntityFormattedSequentialId = sprintf('%03d', (int) $this->billing_entity_sequential_id);
            $formattedYearAndMonth = now()
                ->setTimezone($billingEntity->timezone ?: 'UTC')
                ->format('Ym');

            $this->number = $billingEntity->document_number_prefix.'-'.$formattedYearAndMonth.'-'.$billingEntityFormattedSequentialId;
        }
    }

    /** Port of `set_finalized_at` before_save hook. */
    public function setFinalizedAt(): void
    {
        if (! $this->statusChangedToFinalized()) {
            return;
        }

        $this->finalized_at ??= now();
    }

    /** Refresh `search_terms` for this invoice (port of Invoices::RefreshSearchTermsService). */
    public function refreshSearchTerms(): void
    {
        DB::table('invoices')
            ->where('id', $this->id)
            ->update(['search_terms' => DB::raw(self::searchTermsSql())]);
    }

    // -- Rails before_save hooks ----------------------------------------------

    #[Boot]
    protected static function bootInvoice(): void
    {
        static::saving(function (self $invoice): void {
            $invoice->ensureBillingEntitySequentialId();
            $invoice->ensureNumber();
            $invoice->setFinalizedAt();
        });
    }

    /**
     * Port of: sequenced scope: ->(invoice) { invoice.customer.invoices.where(billing_entity_id:) },
     *          lock_key: ->(invoice) { "#{invoice.customer_id}-#{invoice.billing_entity_id}" }
     */
    protected function sequenceScope(): Builder
    {
        return $this->customer->invoices()->getQuery()->where('billing_entity_id', $this->billing_entity_id);
    }

    protected function sequencedLockKey(): ?string
    {
        return $this->customer_id.'-'.$this->billing_entity_id;
    }

    protected function casts(): array
    {
        return [
            'issuing_date' => 'date:Y-m-d',
            'taxes_amount_cents' => 'integer',
            'total_amount_cents' => 'integer',
            'invoice_type' => InvoiceType::class,
            'payment_status' => InvoicePaymentStatus::class,
            'sequential_id' => 'integer',
            'taxes_rate' => 'float',
            'status' => InvoiceStatus::class,
            'payment_attempts' => 'integer',
            'ready_for_payment_processing' => 'boolean',
            'version_number' => 'integer',
            'fees_amount_cents' => 'integer',
            'coupons_amount_cents' => 'integer',
            'credit_notes_amount_cents' => 'integer',
            'prepaid_credit_amount_cents' => 'integer',
            'sub_total_excluding_taxes_amount_cents' => 'integer',
            'sub_total_including_taxes_amount_cents' => 'integer',
            'payment_due_date' => 'date:Y-m-d',
            'net_payment_term' => 'integer',
            'voided_at' => 'datetime',
            'organization_sequential_id' => 'integer',
            'ready_to_be_refreshed' => 'boolean',
            'payment_dispute_lost_at' => 'datetime',
            'skip_charges' => 'boolean',
            'payment_overdue' => 'boolean',
            'negative_amount_cents' => 'integer',
            'progressive_billing_credit_amount_cents' => 'integer',
            'total_paid_amount_cents' => 'integer',
            'self_billed' => 'boolean',
            'applied_grace_period' => 'integer',
            'billing_entity_sequential_id' => 'integer',
            'finalized_at' => 'datetime',
            'expected_finalization_date' => 'date:Y-m-d',
            'prepaid_granted_credit_amount_cents' => 'integer',
            'prepaid_purchased_credit_amount_cents' => 'integer',
            'skip_automatic_payment' => 'boolean',
            'payment_term' => 'array',
        ];
    }
}
