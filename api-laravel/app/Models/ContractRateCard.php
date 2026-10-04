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
 * Frozen-schema model for `contract_rate_cards`. Port of the Rails
 * ContractRateCard model (app/models/contract_rate_card.rb) — a contract's
 * entry for a rate card, carrying the billing lifecycle (anchor, clock,
 * units) the plan-level entry was materialized from.
 */
#[Fillable([
    'organization_id',
    'contract_id',
    'rate_card_id',
    'billing_anchor_date',
    'next_billing_at',
    'effective_date',
    'units',
])]
#[Table(name: 'contract_rate_cards')]
class ContractRateCard extends BaseModel
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

    /** Rails: `belongs_to :contract`. */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
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

        // Rails: validates :billing_anchor_date, presence: true.
        if (($this->billing_anchor_date ?? null) === null) {
            $errors['billing_anchor_date'] = ['value_is_mandatory'];
        }

        // Rails: presence on create only — nil means the schedule is
        // exhausted, not that the card was made wrong.
        if (! $this->exists && ($this->next_billing_at ?? null) === null) {
            $errors['next_billing_at'] = ['value_is_mandatory'];
        }

        // Rails: validates :effective_date, presence: true.
        if (($this->effective_date ?? null) === null) {
            $errors['effective_date'] = ['value_is_mandatory'];
        }

        // Rails: uniqueness of :rate_card_id scoped to the contract,
        // discarded rows excluded.
        $uniqueness = static::query()
            ->where('rate_card_id', $this->rate_card_id)
            ->where('contract_id', $this->contract_id)
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

    /** Rails: `edit_error_code` — a contract's cards freeze once it is signed. */
    public function editErrorCode(): ?string
    {
        return $this->contract->editable() ? null : 'contract_locked';
    }

    /**
     * Rails: scope `due_for_billing(timestamp)` — cards whose clock has
     * come due on a billable contract (billing-engine entry point; kept for
     * the billing slice).
     */
    public function scopeDueForBilling($query, $timestamp)
    {
        return $query
            ->join('contracts', 'contracts.id', '=', 'contract_rate_cards.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->whereNull('customers.deleted_at')
            ->where('contract_rate_cards.next_billing_at', '<=', $timestamp)
            ->where('contracts.status', Contract::BILLABLE_STATUSES[0])
            ->where('contracts.started_at', '<=', $timestamp)
            ->whereIn('contract_rate_cards.rate_card_id', RateCardRate::query()->select('rate_card_id'))
            ->where(function ($q): void {
                // Only a card with a window: one that starts after its
                // contract ends has no period to owe anything in.
                $q->whereNull('contracts.ended_at')
                    ->orWhereRaw(
                        "contracts.ended_at AT TIME ZONE 'UTC' > (contract_rate_cards.effective_date::timestamp AT TIME ZONE COALESCE(customers.timezone, 'UTC'))",
                    );
            });
    }

    /** Rails bigint columns serialize as integers, never DB strings. */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
        ];
    }
}
