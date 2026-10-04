<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Port of Rails' AddOn (app/models/add_on.rb).
 *
 * Rails uses Discard on deleted_at — the Laravel port is SoftDeletes on the
 * same column (kept scope + withTrashed included in the identifier scopes).
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'description',
    'amount_cents',
    'amount_currency',
    'invoice_display_name',
])]
#[Table(name: 'add_ons')]
class AddOn extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    // -- Scopes ---------------------------------------------------------------

    /** Rails: `ransackable_attributes` — the index search covers both. */
    public static function searchableAttributes(): array
    {
        return ['name', 'code'];
    }

    // -- Relationships --------------------------------------------------------

    /** Rails: `has_many :applied_add_ons`. */
    public function appliedAddOns(): HasMany
    {
        return $this->hasMany(AppliedAddOn::class);
    }

    /** Rails: `has_many :customers, through: :applied_add_ons`. */
    public function customers(): HasManyThrough
    {
        return $this->hasManyThrough(
            Customer::class,
            AppliedAddOn::class,
            'add_on_id',
            'id',
            'id',
            'customer_id',
        );
    }

    public function fixedCharges(): HasMany
    {
        return $this->hasMany(FixedCharge::class);
    }

    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(AddOnTax::class, 'add_on_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            AddOnTax::class,
            'add_on_id',
            'id',
            'id',
            'tax_id',
        );
    }

    /** Rails: `has_many :fees`. */
    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    // -- Domain ---------------------------------------------------------------

    /** Rails: `invoice_name` — the display name falls back to the name. */
    public function invoiceName(): string
    {
        $invoiceDisplayName = $this->invoice_display_name;

        return ($invoiceDisplayName === null || $invoiceDisplayName === '')
            ? $this->name
            : $invoiceDisplayName;
    }

    // -- Validations ----------------------------------------------------------

    /**
     * Port of the Rails validations — presence of name/code, code uniqueness
     * per organization among kept records (partial unique index
     * index_add_ons_on_organization_id_and_code WHERE deleted_at IS NULL),
     * amount_cents > 0 and the currency inclusion.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        } else {
            // Rails: uniqueness {conditions: -> { where(deleted_at: nil) },
            // scope: :organization_id}.
            $uniqueness = static::query()
                ->where('code', $this->code)
                ->where('organization_id', $this->organization_id)
                ->whereNull('deleted_at');

            if ($this->exists) {
                $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($uniqueness->exists()) {
                $errors['code'] = ['value_already_exist'];
            }
        }

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

    /**
     * Laravel-idiom alias of the SoftDeletes scope, mirroring Rails'
     * `default_scope -> { kept }` naming.
     */
    public function scopeKept(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }
}
