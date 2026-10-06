<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\Sequenced;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Invoice (app/models/invoice.rb) for the billing pipeline.
 *
 * Attachments (file / xml_file) go through App\Support\ActiveStorage (the
 * ActiveStorage blobs/attachments rows + a filesystem disk), mirroring
 * Rails' has_one_attached. Not ported (TODO(port)): PaperTrail trace,
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

    /** Rails: CREDIT_NOTES_MIN_VERSION. */
    public const CREDIT_NOTES_MIN_VERSION = 2;

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not, so the NOT NULL DEFAULT columns are
     * declared here to keep reads (and the serializers) identical to Rails.
     */
    protected $attributes = [
        'number' => '',
        'timezone' => 'UTC',
        'taxes_amount_cents' => 0,
        'total_amount_cents' => 0,
        'invoice_type' => 0,
        'payment_status' => 0,
        'taxes_rate' => 0.0,
        'status' => 1,
        'payment_attempts' => 0,
        'ready_for_payment_processing' => true,
        'version_number' => 4,
        'fees_amount_cents' => 0,
        'coupons_amount_cents' => 0,
        'credit_notes_amount_cents' => 0,
        'prepaid_credit_amount_cents' => 0,
        'sub_total_excluding_taxes_amount_cents' => 0,
        'sub_total_including_taxes_amount_cents' => 0,
        'net_payment_term' => 0,
        'organization_sequential_id' => 0,
        'ready_to_be_refreshed' => false,
        'skip_charges' => false,
        'payment_overdue' => false,
        'negative_amount_cents' => 0,
        'progressive_billing_credit_amount_cents' => 0,
        'total_paid_amount_cents' => 0,
        'self_billed' => false,
    ];

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

    /** Rails: has_many :credit_notes (usage-monitoring slice, appended). */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Rails: has_many :progressive_billing_credits, class_name: "Credit",
     * foreign_key: :progressive_billing_invoice_id — the credits applied FROM
     * this progressive-billing invoice onto later subscription invoices.
     */
    public function progressiveBillingCredits(): HasMany
    {
        return $this->hasMany(Credit::class, 'progressive_billing_invoice_id');
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

    /** Rails: has_many :integration_resources, as: :syncable. */
    public function integrationResources(): HasMany
    {
        return $this->hasMany(IntegrationResource::class, 'syncable_id')
            ->where('syncable_type', 'Invoice');
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'invoices_taxes', 'invoice_id', 'tax_id');
    }

    // -- ActiveStorage attachments (file / xml_file) --------------------------

    /** Rails: `invoice.file.attached?`. */
    public function hasFile(): bool
    {
        return \App\Support\ActiveStorage::blob($this, \App\Support\ActiveStorage::FILE) !== null;
    }

    /** Rails: `invoice.xml_file.attached?`. */
    public function hasXmlFile(): bool
    {
        return \App\Support\ActiveStorage::blob($this, \App\Support\ActiveStorage::XML_FILE) !== null;
    }

    /** Port of Invoice#file_url. */
    public function fileUrl(): ?string
    {
        return \App\Support\ActiveStorage::url(
            \App\Support\ActiveStorage::blob($this, \App\Support\ActiveStorage::FILE),
        );
    }

    /** Port of Invoice#xml_url. */
    public function xmlUrl(): ?string
    {
        return \App\Support\ActiveStorage::url(
            \App\Support\ActiveStorage::blob($this, \App\Support\ActiveStorage::XML_FILE),
        );
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

    /**
     * Port of Rails' `should_apply_provider_tax?` (app/models/invoice.rb):
     * `fees.any? && Invoices::TransitionToFinalStatusService
     * .new(invoice: self).should_finalize_invoice?`.
     */
    public function shouldApplyProviderTax(): bool
    {
        return $this->fees()->exists()
            && (new \App\Services\Invoices\TransitionToFinalStatusService(invoice: $this))->shouldFinalizeInvoice();
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

    // -- Validation ------------------------------------------------------------

    /**
     * Port of the model validations the services rely on (validates
     * :issuing_date, :currency, presence: true) — a field => [codes] hash,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->issuing_date === null || $this->issuing_date === '') {
            $errors['issuing_date'] = ['value_is_mandatory'];
        }

        if ($this->currency === null || $this->currency === '') {
            $errors['currency'] = ['value_is_mandatory'];
        }

        return $errors;
    }

    /** Rails `visible?` — the status is not one of the invisible ones. */
    public function isVisible(): bool
    {
        return ! in_array(
            $this->statusEnum(),
            [InvoiceStatus::Generating, InvoiceStatus::Open, InvoiceStatus::Closed, InvoiceStatus::Deleted],
            true,
        );
    }

    /** Rails `is_deleted?` */
    public function isDeleted(): bool
    {
        return $this->statusEnum() === InvoiceStatus::Deleted;
    }

    /** Rails `credit?` — the invoice bills prepaid wallet credits. */
    public function isCredit(): bool
    {
        return $this->typeEnum() === InvoiceType::Credit;
    }

    /** Rails `payment_overdue?` */
    public function isPaymentOverdue(): bool
    {
        return (bool) $this->payment_overdue;
    }

    /**
     * Rails `web_url` — the front-app URL of the invoice; null while the
     * status is one of the invisible ones (generating / open / closed /
     * deleted). URI.join(front_url, "/{org.slug}/customer/{customer_id}/",
     * "invoice/{id}/overview").
     */
    public function webUrl(): ?string
    {
        if (! $this->isVisible()) {
            return null;
        }

        $frontUrl = mb_rtrim((string) config('lago.front_url'), '/');

        return sprintf(
            '%s/%s/customer/%s/invoice/%s/overview',
            $frontUrl,
            $this->organization?->slug,
            $this->customer_id,
            $this->id,
        );
    }

    // -- Creditable / refundable amounts ---------------------------------------
    // NOTE: the credit-note-dependent precision (credit note items, offsets)
    // is not ported yet — see the TODO(port) markers.

    /**
     * Port of `available_to_credit_amount_cents` /
     * `creditable_amount_cents` — the amount cents onto which a credit note
     * can be issued as credit.
     *
     * TODO(port): the booked-tax share (fees_available_to_credit_amount_cents
     * walks creditable_share * subtotal + booked tax per fee) and the credit
     * note allocations (fee.credit_note_items) — until the credit-notes
     * slice lands, the full unallocated subtotal (taxes included) is the
     * ceiling, floored at zero.
     */
    public function creditableAmountCents(): int
    {
        if ($this->isCredit()) {
            return 0;
        }

        if ((int) $this->version_number < self::CREDIT_NOTES_MIN_VERSION || $this->isDraft()) {
            return 0;
        }

        return max((int) $this->sub_total_including_taxes_amount_cents, 0);
    }

    /**
     * Port of `refundable_amount_cents` — the amount cents onto which a
     * credit note can be issued as refund.
     *
     * TODO(port): credit note refund sums (already_refunded_cents) and the
     * prepaid-credit wallet ceiling (prepaid_credit_fee).
     */
    public function refundableAmountCents(): int
    {
        if ((int) $this->version_number < self::CREDIT_NOTES_MIN_VERSION || $this->isDraft()) {
            return 0;
        }

        if (! $this->paymentSucceeded() && (int) $this->total_paid_amount_cents === (int) $this->total_amount_cents) {
            return 0;
        }

        // already_refunded_cents = 0 while credit notes are unported.
        $remainingPaidCents = (int) $this->total_paid_amount_cents;

        $refundableCents = min($remainingPaidCents, $this->creditableAmountCents());

        return max($refundableCents, 0);
    }

    /**
     * Rails: `has_many :payments, as: :payable` — polymorphic payments on
     * the invoice itself (a payment request settlement attaches to the
     * request, not here).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')
            ->where('payable_type', 'Invoice');
    }

    /**
     * Rails: `has_many :payment_requests, through: :invoices_payment_requests`.
     */
    public function paymentRequests(): BelongsToMany
    {
        return $this->belongsToMany(
            PaymentRequest::class,
            'invoices_payment_requests',
            'invoice_id',
            'payment_request_id',
        );
    }

    /**
     * Port of `refundable_payment` — an invoice settled through a payment
     * request has its payment attached to the request, not to the invoice:
     * the invoice's own succeeded payments (latest first) win, else the
     * succeeded payment of a succeeded payment request covering it.
     */
    public function refundablePayment(): ?Payment
    {
        $payment = $this->payments()
            ->where('payable_payment_status', 'succeeded')
            ->orderByDesc('created_at')
            ->first();

        if ($payment !== null) {
            return $payment;
        }

        return Payment::query()
            ->where('payable_type', 'PaymentRequest')
            ->where('payable_payment_status', 'succeeded')
            ->whereIn('payable_id', $this->paymentRequests()->where('payment_status', 1)->select('id'))
            ->orderByDesc('created_at')
            ->first();
    }

    // -- Rails before_save hooks ----------------------------------------------

    protected static function booted(): void
    {
        static::saving(function (self $invoice): void {
            // Rails callback order: Sequenced#ensure_sequential_id first
            // (registered at `include Sequenced`), then the model's own
            // before_save hooks — ensure_number formats the assigned id.
            $invoice->ensureSequentialId();
            $invoice->ensureBillingEntitySequentialId();
            $invoice->ensureNumber();
            $invoice->setFinalizedAt();
        });
    }

    /**
     * Port of `should_assign_sequential_id?` — Rails calls
     * `status_changed?(from:, to:)` for every to-finalized transition; the
     * status attribute is "changed" on the finalizing save. Rails' own flows
     * never INSERT a finalized invoice: the API creates it as a draft /
     * generating row and finalizes it with an update, so the dirty check
     * covers every real transition. A directly-inserted finalized invoice
     * (spec factories, one-off imports) must number immediately as well —
     * the frozen SDL types `Invoice.sequentialId` as non-null, so a NULL
     * would break serialization. Drafts keep their NULL sequential_id until
     * the generating/draft → finalized save.
     */
    protected function shouldAssignSequentialId(): bool
    {
        if (! $this->exists) {
            // NULL status falls back to the column default (finalized).
            return ($this->statusEnum()?->value ?? InvoiceStatus::Finalized->value)
                === InvoiceStatus::Finalized->value;
        }

        return $this->isDirty('status');
    }

    // -- Rails scopes ----------------------------------------------------------

    /** Rails: `scope :visible, -> { where(status: VISIBLE_STATUS.keys) }`. */
    #[Scope]
    protected function visible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Draft->value,
            InvoiceStatus::Finalized->value,
            InvoiceStatus::Voided->value,
            InvoiceStatus::Failed->value,
            InvoiceStatus::Pending->value,
        ]);
    }

    /** Rails: `scope :invisible, -> { where(status: INVISIBLE_STATUS.keys) }`. */
    #[Scope]
    protected function invisible(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Generating->value,
            InvoiceStatus::Open->value,
            InvoiceStatus::Closed->value,
            InvoiceStatus::Deleted->value,
        ]);
    }

    /** Rails: `scope :ready_to_be_refreshed, -> { draft.where(ready_to_be_refreshed: true) }`. */
    #[Scope]
    protected function readyToBeRefreshed(Builder $query): Builder
    {
        return $query
            ->where('status', InvoiceStatus::Draft->value)
            ->where('ready_to_be_refreshed', true);
    }

    /**
     * Rails: `scope :ready_to_be_finalized,
     *   -> { draft.where("COALESCE(expected_finalization_date, issuing_date) <= ?", Time.current.to_date) }`.
     */
    #[Scope]
    protected function readyToBeFinalized(Builder $query): Builder
    {
        return $query
            ->where('status', InvoiceStatus::Draft->value)
            ->whereRaw('coalesce(expected_finalization_date, issuing_date) <= ?', [now()->toDateString()]);
    }

    /**
     * Rails: `scope :with_active_subscriptions, -> { joins(:subscriptions)
     *   .where(subscriptions: {status: "active"}).distinct }` — the EXISTS
     * shape filters identically without clobbering the invoice columns a
     * join would.
     */
    #[Scope]
    protected function withActiveSubscriptions(Builder $query): Builder
    {
        return $query->whereHas('subscriptions', function (Builder $query): void {
            $query->where('subscriptions.status', SubscriptionStatus::Active->value);
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
