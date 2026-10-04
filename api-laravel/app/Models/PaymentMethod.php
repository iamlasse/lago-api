<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payment_methods` (Rails' PaymentMethod).
 *
 * `details` is a JSON object (PaymentMethods::CardDetails shape — type,
 * last4, brand, expiration_month, expiration_year, ...). Discarded
 * (`deleted_at`) methods are hidden, like Rails' `default_scope -> { kept }`.
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'payment_provider_id',
    'payment_provider_customer_id',
    'provider_method_id',
    'provider_method_type',
    'is_default',
    'details',
    'deleted_at',
])]
#[Table(name: 'payment_methods')]
class PaymentMethod extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: PaymentMethod::PAYMENT_METHOD_TYPES. */
    public const PAYMENT_METHOD_TYPES = [
        'provider' => 'provider',
        'manual' => 'manual',
    ];

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

    /** Rails: PaymentMethod#payment_provider_type (the provider slug). */
    public function paymentProviderType(): ?string
    {
        return $this->paymentProvider?->paymentType();
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'details' => 'array',
        ];
    }
}
