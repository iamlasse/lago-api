<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `rate_phases`. Port of the Rails RatePhase model
 * (app/models/rate_phase.rb) — one phase of an applied rate card's pricing
 * timeline, owned by either a plan or a contract rate card (exactly one).
 */
#[Fillable([
    'organization_id',
    'plan_rate_card_id',
    'contract_rate_card_id',
    'code',
    'position',
    'billing_interval_cycle_count',
    'rate_override_id',
    'name',
])]
#[Table(name: 'rate_phases')]
class RatePhase extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Domain methods ---------------------------------------------------------

    /**
     * Rails: `RatePhase.parse_position` — positions arrive as integers
     * (GraphQL, JSON numbers) or strings (REST). A boolean, a decimal or a
     * stray word is not a position: null, never coerced.
     */
    public static function parsePosition(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `belongs_to :plan_rate_card, optional: true`. */
    public function planRateCard(): BelongsTo
    {
        return $this->belongsTo(PlanRateCard::class);
    }

    /** Rails: `belongs_to :contract_rate_card, optional: true`. */
    public function contractRateCard(): BelongsTo
    {
        return $this->belongsTo(ContractRateCard::class);
    }

    /** Rails: `belongs_to :rate_override, optional: true`. */
    public function rateOverride(): BelongsTo
    {
        return $this->belongsTo(RateOverride::class);
    }

    /** The applied rate card owning the phase (plan or contract side). */
    public function appliedRateCard(): PlanRateCard|ContractRateCard|null
    {
        return $this->planRateCard ?? $this->contractRateCard;
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

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];

            return $errors;
        }

        if (preg_match('/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/', (string) $this->code) !== 1) {
            $errors['code'] = ['value_is_invalid'];

            return $errors;
        }

        // Rails: exactly one parent (ParentPresenceValidator,
        // error: :exactly_one_parent_required).
        if (($this->plan_rate_card_id === null) === ($this->contract_rate_card_id === null)) {
            $errors['base'] = ['exactly_one_parent_required'];

            return $errors;
        }

        // Rails: uniqueness of :code scoped to the owning card (kept rows).
        $uniqueness = static::query()
            ->where('code', $this->code)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                if ($this->plan_rate_card_id !== null) {
                    $query->where('plan_rate_card_id', $this->plan_rate_card_id);
                } else {
                    $query->where('contract_rate_card_id', $this->contract_rate_card_id);
                }
            });

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['code'] = ['value_already_exist'];
        }

        // Rails: position presence + numericality > 0.
        if ($this->position === null) {
            $errors['position'] = ['value_is_mandatory'];
        } elseif ((int) $this->position < 1) {
            $errors['position'] = ['value_is_out_of_range'];
        }

        // Rails: nil = indefinite (terminal) phase; a zero-cycle phase
        // would never bill.
        if ($this->billing_interval_cycle_count !== null && (int) $this->billing_interval_cycle_count < 1) {
            $errors['billing_interval_cycle_count'] = ['value_is_out_of_range'];
        }

        // Rails: uniqueness of :rate_override_id (kept rows), allow_nil.
        if ($this->rate_override_id !== null) {
            $overrideUniqueness = static::query()
                ->where('rate_override_id', $this->rate_override_id)
                ->whereNull('deleted_at');

            if ($this->exists) {
                $overrideUniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($overrideUniqueness->exists()) {
                $errors['rate_override_id'] = ['value_already_exist'];
            }
        }

        return $errors;
    }
}
