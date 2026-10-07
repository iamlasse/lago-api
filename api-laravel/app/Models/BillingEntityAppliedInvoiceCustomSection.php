<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `billing_entities_invoice_custom_sections` — Rails'
 * BillingEntity::AppliedInvoiceCustomSection
 * (app/models/billingentity/applied_invoice_custom_section.rb):
 * a custom section selected for one billing_entity.
 */
#[Fillable([
    'organization_id',
    'billing_entity_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'billing_entities_invoice_custom_sections')]
class BillingEntityAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :billing_entity`. */
    public function billing_entity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    /** Rails: `belongs_to :invoice_custom_section`. */
    public function invoiceCustomSection(): BelongsTo
    {
        return $this->belongsTo(InvoiceCustomSection::class);
    }

    protected function casts(): array
    {
        return [

        ];
    }
}
