<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Entitlement::EntitlementValue
 * (app/models/entitlement/entitlement_value.rb) — the value one privilege
 * takes on one entitlement. Stored in `entitlement_entitlement_values`.
 */
#[Fillable([
    'organization_id',
    'entitlement_privilege_id',
    'entitlement_entitlement_id',
    'value',
    'deleted_at',
])]
#[Table(name: 'entitlement_entitlement_values')]
class EntitlementValue extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Relationships ------------------------------------------------------------

    /** Rails: `belongs_to :privilege, foreign_key: :entitlement_privilege_id`. */
    public function privilege(): BelongsTo
    {
        return $this->belongsTo(Privilege::class, 'entitlement_privilege_id');
    }

    /** Rails: `belongs_to :entitlement, foreign_key: :entitlement_entitlement_id`. */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class, 'entitlement_entitlement_id');
    }

    // -- Validations ------------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->entitlement_privilege_id ?? '') === '') {
            $errors['entitlement_privilege_id'] = ['value_is_mandatory'];
        }

        if (($this->entitlement_entitlement_id ?? '') === '') {
            $errors['entitlement_entitlement_id'] = ['value_is_mandatory'];
        }

        if (($this->value ?? '') === '') {
            $errors['value'] = ['value_is_mandatory'];
        }

        return $errors;
    }

    // -- Attribute behavior ------------------------------------------------------

    /**
     * Rails: the `value` column is a string — ActiveRecord's String type
     * casts booleans to "t" / "f" on write; Eloquent binds the raw bool.
     * (spec: entitlement_value.value eq "t" for a true boolean privilege.)
     */
    protected function value(): Attribute
    {
        return Attribute::set(
            fn (mixed $value): mixed => is_bool($value) ? ($value ? 't' : 'f') : $value,
        );
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
