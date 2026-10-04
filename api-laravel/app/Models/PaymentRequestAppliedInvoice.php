<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `invoices_payment_requests` (Rails'
 * PaymentRequest::AppliedInvoice — the payment request <-> invoice join).
 */
#[Fillable([
    'invoice_id',
    'payment_request_id',
    'organization_id',
])]
#[Table(name: 'invoices_payment_requests')]
class PaymentRequestAppliedInvoice extends BaseModel
{
    use HasFactory;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
