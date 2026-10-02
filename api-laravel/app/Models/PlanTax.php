<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `plans_taxes` (Rails' Plan::AppliedTax). Created by
 * the Plans::ApplyTaxesService port. NOTE: the frozen schema carries a CHECK
 * constraint — exactly one of plan_id / catalog_plan_id must be set; the
 * legacy-engine port only ever writes plan_id.
 */
#[Fillable([
    'plan_id',
    'tax_id',
    'organization_id',
    'catalog_plan_id',
])]
#[Table(name: 'plans_taxes')]
class PlanTax extends BaseModel
{
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    protected function casts(): array
    {
        return [

        ];
    }
}
