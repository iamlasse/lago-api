<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `dunning_campaign_thresholds` (Rails'
 * DunningCampaignThreshold). SoftDeletes == Rails' Discard (deleted_at,
 * default_scope -> { kept }).
 */
#[Fillable([
    'dunning_campaign_id',
    'organization_id',
    'currency',
    'amount_cents',
])]
#[Table(name: 'dunning_campaign_thresholds')]
class DunningCampaignThreshold extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function dunningCampaign(): BelongsTo
    {
        return $this->belongsTo(DunningCampaign::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // -- Validations (port of the Rails model errors) --------------------------

    /**
     * Port of the Rails validations — amount_cents >= 0, currency inclusion
     * in the currency list, per-campaign currency uniqueness over kept rows.
     * Returns field => [codes].
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ((int) $this->amount_cents < 0) {
            // Rails: numericality {greater_than_or_equal_to: 0}.
            $errors['amount_cents'] = ['value_is_out_of_range'];
        }

        if (! \App\Services\Validators\Currencies::valid((string) $this->currency)) {
            // Rails: validates :currency, inclusion: {in: currency_list}.
            $errors['currency'] = ['value_is_invalid'];
        } else {
            // Rails: uniqueness {conditions: -> { where(deleted_at: nil) },
            // scope: :dunning_campaign_id} (backed by a partial unique index).
            $uniqueness = static::query()
                ->where('currency', $this->currency)
                ->where('dunning_campaign_id', $this->dunning_campaign_id)
                ->whereNull('deleted_at');

            if ($this->exists) {
                $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($uniqueness->exists()) {
                $errors['currency'] = ['value_already_exist'];
            }
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
