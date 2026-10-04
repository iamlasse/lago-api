<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `plan_rate_cards`. Port of the Rails PlanRateCard
 * model (app/models/plan_rate_card.rb) — a catalog plan's entry for a rate
 * card (the plan's "applied rate card").
 */
#[Fillable([
    'organization_id',
    'catalog_plan_id',
    'rate_card_id',
    'units',
])]
#[Table(name: 'plan_rate_cards')]
class PlanRateCard extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `belongs_to :catalog_plan`. */
    public function catalogPlan(): BelongsTo
    {
        return $this->belongsTo(CatalogPlan::class);
    }

    /** Rails: `belongs_to :rate_card`. */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    /** Rails: `has_one :product, through: :rate_card`. */
    public function product()
    {
        return $this->hasOneThrough(
            Product::class,
            RateCard::class,
            'id',
            'id',
            'rate_card_id',
            'product_id',
        );
    }

    /** Rails: `has_many :rate_phases, -> { order(:position) }`. */
    public function ratePhases(): HasMany
    {
        return $this->hasMany(RatePhase::class)->orderBy('position');
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails validations — `field => [api error codes]`.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // Rails: uniqueness of :rate_card_id scoped to the catalog plan,
        // discarded rows excluded — one entry per card.
        $uniqueness = static::query()
            ->where('rate_card_id', $this->rate_card_id)
            ->where('catalog_plan_id', $this->catalog_plan_id)
            ->whereNull('deleted_at');

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['rate_card_id'] = ['value_already_exist'];
        }

        // Rails: numericality >= 0, allow_nil.
        if ($this->units !== null && (float) $this->units < 0) {
            $errors['units'] = ['value_is_out_of_range'];
        }

        return $errors;
    }

    // -- Domain methods ---------------------------------------------------------

    /** Rails: `edit_error_code` — a plan with contracts is locked. */
    public function editErrorCode(): ?string
    {
        return $this->catalogPlan->attachedToContracts() ? 'plan_locked' : null;
    }
}
