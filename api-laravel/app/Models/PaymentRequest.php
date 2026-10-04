<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Frozen-schema model for `payment_requests` (Rails' PaymentRequest).
 *
 * `payment_status` is an integer column — Rails'
 * `enum :payment_status, %i[pending succeeded failed]`, 0-based (never
 * renumber). Invoices are linked through the `invoices_payment_requests`
 * join table (Rails: PaymentRequest::AppliedInvoice).
 */
#[Fillable([
    'customer_id',
    'amount_cents',
    'amount_currency',
    'email',
    'organization_id',
    'payment_status',
    'payment_attempts',
    'ready_for_payment_processing',
    'dunning_campaign_id',
])]
#[Table(name: 'payment_requests')]
class PaymentRequest extends BaseModel
{
    use HasFactory;

    /** Rails: PaymentRequest::PAYMENT_STATUS (enum order = stored value). */
    public const PAYMENT_STATUSES = ['pending', 'succeeded', 'failed'];

    /** Rails: default payment_status — "pending" (0). */
    protected $attributes = [
        'payment_status' => 0,
        'payment_attempts' => 0,
        'ready_for_payment_processing' => true,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: PaymentRequest::AppliedInvoice — the join rows. */
    public function appliedInvoices(): HasMany
    {
        return $this->hasMany(PaymentRequestAppliedInvoice::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(
            Invoice::class,
            'invoices_payment_requests',
            'payment_request_id',
            'invoice_id',
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')->where('payable_type', 'PaymentRequest');
    }

    /** Rails: `enum :payment_status, prefix: :payment` -> payment_succeeded?. */
    public function paymentStatus(): string
    {
        return self::PAYMENT_STATUSES[(int) $this->payment_status] ?? 'pending';
    }

    public function setPaymentStatus(string $status): void
    {
        $this->payment_status = array_search($status, self::PAYMENT_STATUSES, true);
    }

    public function paymentSucceeded(): bool
    {
        return $this->paymentStatus() === 'succeeded';
    }

    /** Rails: alias total_amount_cents / currency. */
    public function totalAmountCents(): int
    {
        return (int) $this->amount_cents;
    }

    /** Rails: PaymentRequest#increment_payment_attempts!. */
    public function incrementPaymentAttempts(): void
    {
        $this->payment_attempts = (int) $this->payment_attempts + 1;
        $this->save();
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'payment_status' => 'integer',
            'payment_attempts' => 'integer',
            'ready_for_payment_processing' => 'boolean',
        ];
    }
}
