<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `subscriptions_invoice_custom_sections` — Rails'
 * Subscription::AppliedInvoiceCustomSection
 * (app/models/subscription/applied_invoice_custom_section.rb):
 * a custom section selected for one subscription.
 */
#[Fillable([
    'organization_id',
    'subscription_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'subscriptions_invoice_custom_sections')]
class SubscriptionAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :subscription`. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
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
