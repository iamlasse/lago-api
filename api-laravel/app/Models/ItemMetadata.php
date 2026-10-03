<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Metadata::ItemMetadata (app/models/metadata/item_metadata.rb)
 * — the free-form metadata hash attached to a Wallet (or other billing
 * object) through the polymorphic item_metadata table.
 *
 * `value` is a jsonb OBJECT of scalar key/value pairs; the constraints
 * Rails validates are ported in validateAttributes().
 *
 * NOTE: Rails' `belongs_to :owner, polymorphic: true` has no direct port —
 * owner_type stores the Rails class name ("Wallet", …) and there is no
 * Laravel morph map; consumers query the owner rows by id.
 */
#[Table(name: 'item_metadata')]
class ItemMetadata extends BaseModel
{
    use HasFactory;

    public const MAX_NUMBER_OF_KEYS = 50;

    public const MAX_KEY_LENGTH = 100;

    public const MAX_VALUE_LENGTH = 255;

    protected $fillable = [
        'organization_id',
        'owner_type',
        'owner_id',
        'value',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Rails owner_type is the RAILS class name ("Wallet", …); this port
     * stores the same string so the audit trail and queries match.
     */
    public function railsName(): string
    {
        return 'Metadata::ItemMetadata';
    }

    /**
     * Port of the `value_correctness` validation — field => [codes].
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->value === null) {
            $errors['value'] = ['blank'];

            return $errors;
        }

        if (! is_array($this->value)) {
            $errors['value'] = ['must_be_a_hash'];

            return $errors;
        }

        if (count($this->value) > self::MAX_NUMBER_OF_KEYS) {
            $errors['value'] = ['cannot_have_more_than_max_keys'];
        }

        foreach ($this->value as $key => $val) {
            if (! is_string($key) || mb_strlen($key) > self::MAX_KEY_LENGTH) {
                $errors['value'][] = sprintf('key_%s_must_be_a_string_up_to_max_key_length_characters', $key);

                continue;
            }

            if ($val !== null && ! is_string($val)) {
                $errors['value'][] = sprintf('value_for_key_%s_must_be_empty_or_a_string', $key);
            } elseif (is_string($val) && mb_strlen($val) > self::MAX_VALUE_LENGTH) {
                $errors['value'][] = sprintf('value_for_key_%s_must_be_empty_or_a_string', $key);
            }
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
