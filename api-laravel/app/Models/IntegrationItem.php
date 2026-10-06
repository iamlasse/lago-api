<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `integration_items` (Rails' IntegrationItem).
 * Added by the integrations GraphQL slice (the model did not exist yet;
 * report: new file, no existing model touched).
 */
#[Fillable([
    'integration_id',
    'item_type',
    'external_id',
    'external_account_code',
    'external_name',
    'organization_id',
])]
#[Table(name: 'integration_items')]
class IntegrationItem extends BaseModel
{
    /** Rails: ITEM_TYPES (enum :item_type order — never renumber). */
    public const ITEM_TYPES = ['standard', 'tax', 'account'];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'item_type' => 'integer',
        ];
    }
}
