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

    /** Rails STI type strings (frozen `type` column values). */
    public const ANROK_TYPE = 'IntegrationCustomers::AnrokCustomer';

    public const AVALARA_TYPE = 'IntegrationCustomers::AvalaraCustomer';

    /** Rails: BaseCustomer.customer_type(:anrok) — the STI type per provider key. */
    public const PROVIDER_TYPES = [
        'anrok' => self::ANROK_TYPE,
        'avalara' => self::AVALARA_TYPE,
    ];

    /**
     * Rails: BaseCustomer#tax_kind? — the customer_type is one of the tax
     * provider kinds (anrok / avalara).
     */
    public function taxKind(): bool
    {
        return in_array($this->type, [self::ANROK_TYPE, self::AVALARA_TYPE], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
