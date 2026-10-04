<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' AppliedAddOn (app/models/applied_add_ons.rb) — an add-on
 * applied to a customer (amount snapshot taken from the add-on at apply
 * time). In the current Rails surface the records are read-only through the
 * API (GraphQL customer.appliedAddOns / addOn.appliedAddOnsCount).
 */
#[Fillable([
    'add_on_id',
    'customer_id',
    'amount_cents',
    'amount_currency',
])]
#[Table(name: 'applied_add_ons')]
class AppliedAddOn extends BaseModel
{
    use HasFactory;
    use HasUuid;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :add_on`. */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    /** Rails: `belongs_to :customer`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // -- Validations ----------------------------------------------------------

    /**
     * Port of the Rails validations — amount_cents > 0 and the currency
     * inclusion.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->amount_cents === null || (int) $this->amount_cents <= 0) {
            $errors['amount_cents'] = ['invalid_amount'];
        }

        if (($this->amount_currency ?? '') === '') {
            $errors['amount_currency'] = ['value_is_mandatory'];
        } elseif (! Currencies::valid($this->amount_currency)) {
            $errors['amount_currency'] = ['value_is_invalid'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }
}
