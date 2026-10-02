<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Port of Rails' Credit (app/models/credit.rb) — invoice credits from
 * coupons, progressive billing, credit notes and prepaid wallets.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'invoice_id',
    'applied_coupon_id',
    'amount_cents',
    'amount_currency',
    'credit_note_id',
    'before_taxes',
    'progressive_billing_invoice_id',
    'organization_id',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'credits')]
class Credit extends BaseModel
{
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function appliedCoupon()
    {
        return $this->belongsTo(AppliedCoupon::class);
    }

    public function progressiveBillingInvoice()
    {
        return $this->belongsTo(Invoice::class, 'progressive_billing_invoice_id');
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'before_taxes' => 'boolean',
        ];
    }
}
