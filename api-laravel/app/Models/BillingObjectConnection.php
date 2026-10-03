<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `billing_object_connections` (Rails'
 * BillingObjectConnection): an explicit per-billing-object override of the
 * connection (payment / tax / accounting / crm) it uses.
 *
 * The owner is polymorphic in the schema (owner_type + owner_id), but
 * `owner_type` stores the RAILS class name ("Subscription", "Wallet", …), so
 * owners declare the relation themselves — see
 * ConnectionResolvable::billingObjectConnections(), which constrains on
 * railsName() rather than using a Laravel morph map.
 */
#[Fillable([
    'organization_id',
    'owner_type',
    'owner_id',
    'payment_provider_customer_id',
    'integration_customer_id',
    'category',
    'behavior',
])]
#[Table(name: 'billing_object_connections')]
class BillingObjectConnection extends BaseModel
{
    use HasFactory;

    /** Rails: BillingObjectConnection::CATEGORIES (order is serialization order). */
    public const CATEGORIES = [
        'payment' => 'payment',
        'tax' => 'tax',
        'accounting' => 'accounting',
        'crm' => 'crm',
    ];

    /** Rails: BillingObjectConnection::BEHAVIORS — the column only holds these two. */
    public const BEHAVIORS = [
        'specific' => 'specific',
        'skip' => 'skip',
    ];

    public function paymentProviderCustomer(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderCustomer::class);
    }

    public function integrationCustomer(): BelongsTo
    {
        return $this->belongsTo(IntegrationCustomer::class);
    }

    protected function casts(): array
    {
        return [
            'category' => 'string',
            'behavior' => 'string',
        ];
    }
}
