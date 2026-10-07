<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `customers_invoice_custom_sections` — Rails'
 * Customer::AppliedInvoiceCustomSection
 * (app/models/customer/applied_invoice_custom_section.rb):
 * a custom section selected for one customer.
 */
#[Fillable([
    'organization_id',
    'billing_entity_id',
    'customer_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'customers_invoice_custom_sections')]
class CustomerAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [

        ];
    }

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :customer`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Rails: `belongs_to :billing_entity`. */
    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    /** Rails: `belongs_to :invoice_custom_section`. */
    public function invoiceCustomSection(): BelongsTo
    {
        return $this->belongsTo(InvoiceCustomSection::class);
    }
}

