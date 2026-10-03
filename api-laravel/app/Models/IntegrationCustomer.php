<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `integration_customers` (Rails'
 * IntegrationCustomers::BaseCustomer — single-table inheritance on `type`;
 * subclasses are not ported yet, so the base model carries the columns).
 */
#[Fillable([
    'integration_id',
    'customer_id',
    'external_customer_id',
    'type',
    'settings',
    'organization_id',
    'code',
    'is_default',
    'category',
])]
#[Table(name: 'integration_customers')]
class IntegrationCustomer extends BaseModel
{
    use HasFactory;

    /** Rails: IntegrationCustomers::BaseCustomer::CATEGORIES. */
    public const CATEGORIES = [
        'payment' => 'payment',
        'tax' => 'tax',
        'accounting' => 'accounting',
        'crm' => 'crm',
    ];

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
