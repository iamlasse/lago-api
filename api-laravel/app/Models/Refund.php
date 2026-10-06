<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `refunds` (Rails' Refund).
 *
 * A provider refund of a credit note's refund leg: it hangs off the
 * payment that settled the source invoice (`payment_id`), carries the
 * provider's refund id and status, and points at the credit note
 * (`credit_note_id`) and its polymorphic `refundable` (Rails allows
 * non-credit-note refundables — subscription_activation_expired — hence
 * the CHECK constraint requiring one of the two).
 *
 * REASONS mirrors Rails' `enum :reason` ({credit_note: "credit_note",
 * subscription_activation_expired: "..."} — string-backed, nullable).
 */
#[Fillable([
    'payment_id',
    'credit_note_id',
    'payment_provider_id',
    'payment_provider_customer_id',
    'amount_cents',
    'amount_currency',
    'status',
    'provider_refund_id',
    'organization_id',
    'refundable_type',
    'refundable_id',
    'reason',
])]
#[Table(name: 'refunds')]
class Refund extends BaseModel
{
    use HasFactory;

    /** Rails: Refund::REASONS. */
    public const REASONS = [
        'credit_note' => 'credit_note',
        'subscription_activation_expired' => 'subscription_activation_expired',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function refundable(): MorphTo
    {
        return $this->morphTo();
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function paymentProviderCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
