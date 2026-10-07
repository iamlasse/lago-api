<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payments` (Rails' Payment).
 *
 * `status` is the raw provider status (e.g. Stripe's "succeeded");
 * `payable_payment_status` is the normalized one (Rails'
 * `enum :payable_payment_status` of pending/processing/succeeded/failed —
 * a Postgres enum column, cast to string here).
 *
 * Rails' `for_organization` scope joins the polymorphic payable so only
 * invoices in a visible status (or any payment request of the org) are
 * listed; see App\Queries\PaymentsQuery.
 */
#[Fillable([
    'invoice_id',
    'payment_provider_id',
    'payment_provider_customer_id',
    'amount_cents',
    'amount_currency',
    'provider_payment_id',
    'status',
    'payable_type',
    'payable_id',
    'provider_payment_data',
    'payable_payment_status',
    'payment_type',
    'reference',
    'provider_payment_method_data',
    'provider_payment_method_id',
    'organization_id',
    'customer_id',
    'error_code',
    'payment_method_id',
])]
#[Table(name: 'payments')]
class Payment extends BaseModel
{
    use HasFactory;

    /** Rails: Payment::PAYABLE_PAYMENT_STATUS. */
    public const PAYABLE_PAYMENT_STATUSES = ['pending', 'processing', 'succeeded', 'failed'];

    /** Rails: Payment::PAYMENT_TYPES (payment_type pg enum order). */
    public const PAYMENT_TYPES = ['provider', 'manual', 'x402'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function paymentProviderCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Rails: payable_payment_status enum accessor ("succeeded" etc). */
    public function payablePaymentStatus(): ?string
    {
        return $this->payable_payment_status === null
            ? null
            : (is_string($this->payable_payment_status)
                ? $this->payable_payment_status
                : (string) $this->payable_payment_status);
    }

    /**
     * Rails: Payment#should_sync_payment? — the emission guard of the
     * payment services that enqueue the accounting integrations collector:
     * `payable.is_a?(Invoice) && payable.finalized? && succeeded? &&
     * customer.integration_customers.accounting_kind.any? { _1.integration.sync_payments }`.
     */
    public function shouldSyncPayment(): bool
    {
        $invoice = $this->payable;

        if (! $invoice instanceof Invoice || ! $invoice->isFinalized()) {
            return false;
        }

        if ($this->payablePaymentStatus() !== 'succeeded') {
            return false;
        }

        return $invoice->customer
            ->integrationCustomers()
            ->accountingKind()
            ->get()
            ->contains(fn ($integrationCustomer) => (bool) $integrationCustomer->integration?->getFromSettings('sync_payments'));
    }

    /**
     * Rails: scope :for_organization — only payments whose payable is a
     * visible invoice of the organization, or a payment request of it.
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forOrganization($query, Organization $organization)
    {
        return $query->where('payments.organization_id', $organization->id)
            ->where(function ($q) use ($organization): void {
                $q->whereExists(function ($sub) use ($organization): void {
                    $sub->selectRaw(1)
                        ->from('invoices')
                        ->whereColumn('invoices.id', 'payments.payable_id')
                        ->whereColumn('payments.payable_type', DB::raw("'Invoice'"))
                        // Rails: Invoice::VISIBLE_STATUS.
                        ->whereIn('invoices.status', [0, 1, 2, 4, 7])
                        ->where('invoices.organization_id', $organization->id);
                })->orWhereExists(function ($sub) use ($organization): void {
                    $sub->selectRaw(1)
                        ->from('payment_requests')
                        ->whereColumn('payment_requests.id', 'payments.payable_id')
                        ->whereColumn('payments.payable_type', DB::raw("'PaymentRequest'"))
                        ->where('payment_requests.organization_id', $organization->id);
                });
            });
    }

    /**
     * Polymorphic payable (Rails: belongs_to :payable, polymorphic: true).
     * The `payable_type` column stores Rails class names ("Invoice" /
     * "PaymentRequest") — mapped to the Laravel classes via the global
     * morph map registered in AppServiceProvider.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    // -- payment-receipts slice (appended) -----------------------------------

    /**
     * Rails: has_one :payment_receipt (payment_receipts.payment_id UNIQUE —
     * the receipt is created at most once per payment by
     * PaymentReceipts::CreateService).
     */
    public function paymentReceipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'provider_payment_data' => 'array',
            'provider_payment_method_data' => 'array',
        ];
    }
}
