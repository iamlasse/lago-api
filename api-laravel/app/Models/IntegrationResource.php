<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `integration_resources` (Rails'
 * IntegrationResource) — the provider-side ids of synced objects (invoices,
 * credit notes). Currently written by the Avalara tax flow; refine as the
 * aggregator sync slices land.
 */
#[Fillable([
    'integration_id',
    'syncable_id',
    'syncable_type',
    'external_id',
    'resource_type',
    'organization_id',
])]
#[Table(name: 'integration_resources')]
class IntegrationResource extends BaseModel
{
    use HasFactory;

    /** Rails: resource_type enum (:invoice default 0). */
    public const int RESOURCE_TYPE_INVOICE = 0;

    public const int RESOURCE_TYPE_SALES_ORDER_DEPRECATED = 1;

    public const int RESOURCE_TYPE_PAYMENT = 2;

    public const int RESOURCE_TYPE_CREDIT_NOTE = 3;

    public const int RESOURCE_TYPE_SUBSCRIPTION = 4;

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    protected function casts(): array
    {
        return [
            'resource_type' => 'integer',
        ];
    }
}
