<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Invoice::AppliedTax (app/models/invoice/applied_tax.rb) —
 * the frozen `invoices_taxes` snapshot rows written when invoice-level
 * taxes are computed.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'invoice_id',
    'tax_id',
    'tax_description',
    'tax_code',
    'tax_name',
    'tax_rate',
    'amount_cents',
    'amount_currency',
    'fees_amount_cents',
    'taxable_base_amount_cents',
    'organization_id',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'invoices_taxes')]
class InvoiceAppliedTax extends BaseModel
{
    use HasFactory;

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    protected function casts(): array
    {
        return [
            'tax_rate' => 'float',
            'amount_cents' => 'integer',
            'fees_amount_cents' => 'integer',
            'taxable_base_amount_cents' => 'integer',
        ];
    }
}
