<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `applied_invoice_custom_sections` — Rails'
 * AppliedInvoiceCustomSection (app/models/applied_invoice_custom_section.rb):
 * the per-invoice snapshot of a custom section at billing time (the section's
 * name / code / display_name / details are copied, so later edits to the
 * source section never mutate issued invoices).
 */
#[Fillable([
    'invoice_id',
    'organization_id',
    'code',
    'details',
    'display_name',
    'name',
])]
#[Table(name: 'applied_invoice_custom_sections')]
class AppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :invoice`. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    protected function casts(): array
    {
        return [

        ];
    }
}
