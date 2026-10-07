<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `wallets_invoice_custom_sections` — Rails'
 * Wallet::AppliedInvoiceCustomSection
 * (app/models/wallet/applied_invoice_custom_section.rb):
 * a custom section selected for one wallet.
 */
#[Fillable([
    'organization_id',
    'wallet_id',
    'invoice_custom_section_id',
])]
#[Table(name: 'wallets_invoice_custom_sections')]
class WalletAppliedInvoiceCustomSection extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :wallet`. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
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
