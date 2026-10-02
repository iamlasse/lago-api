<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `customer_metadata` (Rails Metadata::CustomerMetadata).
 * Refined with relations, the COUNT_PER_CUSTOMER constant and validations.
 */
#[Fillable([
    'customer_id',
    'key',
    'value',
    'display_in_invoice',
    'organization_id',
])]
#[Table(name: 'customer_metadata')]
class CustomerMetadata extends BaseModel
{
    use HasFactory;

    /** Rails: Metadata::CustomerMetadata::COUNT_PER_CUSTOMER. */
    public const COUNT_PER_CUSTOMER = 5;

    // -- Relationships ----------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->key ?? '') === '') {
            $errors['key'] = ['value_is_mandatory'];
        } else {
            if (mb_strlen($this->key) > 20) {
                $errors['key'] = ['value_is_too_long'];
            }

            $uniqueness = static::query()
                ->where('key', $this->key)
                ->where('customer_id', $this->customer_id);

            if ($this->exists) {
                $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($uniqueness->exists()) {
                $errors['key'] = [...($errors['key'] ?? []), 'value_already_exist'];
            }
        }

        if (($this->value ?? '') === '') {
            $errors['value'] = ['value_is_mandatory'];
        } elseif (mb_strlen($this->value) > 100) {
            $errors['value'] = ['value_is_too_long'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'display_in_invoice' => 'boolean',
        ];
    }
}
