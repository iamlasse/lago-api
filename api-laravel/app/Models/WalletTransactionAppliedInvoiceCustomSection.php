<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `wallet_transactions_invoice_custom_sections` — Rails'
 * WalletTransaction::AppliedInvoiceCustomSection
 * (app/models/wallettransaction/applied_invoice_custom_section.rb):
 * a custom section selected for one wallet_transaction.
 */
#[Fillable([
    'organization_id',
    'wallet_transaction_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'wallet_transactions_invoice_custom_sections')]
class WalletTransactionAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :wallet_transaction`. */
    public function wallet_transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
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
