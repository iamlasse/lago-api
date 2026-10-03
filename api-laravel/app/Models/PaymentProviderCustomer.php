<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `payment_provider_customers` (Rails'
 * PaymentProviderCustomers::BaseCustomer — single-table inheritance on
 * `type`; subclasses are not ported yet, so the base model carries the
 * columns).
 */
#[Fillable([
    'customer_id',
    'payment_provider_id',
    'type',
    'provider_customer_id',
    'settings',
    'deleted_at',
    'organization_id',
    'code',
    'is_default',
])]
#[Table(name: 'payment_provider_customers')]
class PaymentProviderCustomer extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
