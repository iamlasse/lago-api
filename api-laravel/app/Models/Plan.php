<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanInterval;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `plans` — refined with the Rails Plan model's
 * relations, enums, scopes and validations (app/models/plan.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): metadata (Metadata::ItemMetadata), usage_thresholds,
 *   entitlements, add-on coupon targets and Clickhouse activity logs.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'name',
    'code',
    'interval',
    'description',
    'amount_cents',
    'amount_currency',
    'trial_period',
    'pay_in_advance',
    'bill_charges_monthly',
    'parent_id',
    'pending_deletion',
    'invoice_display_name',
    'bill_fixed_charges_monthly',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'plans')]
class Plan extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: Plan::INTERVALS — integer enum, see App\Enums\PlanInterval. */
    public const INTERVALS = ['weekly', 'monthly', 'yearly', 'quarterly', 'semiannual'];

    /** Rails schema defaults, mirrored for new instances. */
    protected $attributes = [
        'pay_in_advance' => false,
        'pending_deletion' => false,
        'bill_fixed_charges_monthly' => false,
    ];

    // -- Relationships -----------------------------------------------------------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Rails: `has_one :minimum_commitment, -> { where(commitment_type: :minimum_commitment) }`. */
    public function minimumCommitment(): HasMany
    {
        return $this->hasMany(Commitment::class)
            ->where('commitment_type', 0);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    /** Rails: `has_many :charges, dependent: :destroy`. */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** Rails: `has_many :billable_metrics, through: :charges` — the service
     * ports only need the charge-level lookups today (not ported). */
    public function fixedCharges(): HasMany
    {
        return $this->hasMany(FixedCharge::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Rails: `has_many :applied_taxes, class_name: "Plan::AppliedTax"`. */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(PlanTax::class, 'plan_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            PlanTax::class,
            'plan_id',
            'id',
            'id',
            'tax_id',
        );
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `scope :parents, -> { where(parent_id: nil) }`. */
    public function scopeParents(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    // -- Enum helpers (Rails enum suffix methods) -----------------------------------

    public function weekly(): bool
    {
        return $this->interval === PlanInterval::Weekly->value;
    }

    public function monthly(): bool
    {
        return $this->interval === PlanInterval::Monthly->value;
    }

    public function yearly(): bool
    {
        return $this->interval === PlanInterval::Yearly->value;
    }

    public function quarterly(): bool
    {
        return $this->interval === PlanInterval::Quarterly->value;
    }

    public function semiannual(): bool
    {
        return $this->interval === PlanInterval::Semiannual->value;
    }

    public function isParent(): bool
    {
        return ! $this->isChild();
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    public function payInArrears(): bool
    {
        return ! $this->pay_in_advance;
    }

    /** A legacy plan is frozen once it has subscriptions. */
    public function attachedToSubscriptions(): bool
    {
        return $this->subscriptions()->exists();
    }

    public function hasTrial(): bool
    {
        return $this->trial_period !== null && $this->trial_period > 0;
    }

    public function chargesBilledInMonthlySplitIntervals(): bool
    {
        return (bool) $this->bill_charges_monthly && ($this->yearly() || $this->semiannual());
    }

    public function fixedChargesBilledInMonthlySplitIntervals(): bool
    {
        return (bool) $this->bill_fixed_charges_monthly && ($this->yearly() || $this->semiannual());
    }

    public function chargesOrFixedChargesBilledInMonthlySplitIntervals(): bool
    {
        return $this->chargesBilledInMonthlySplitIntervals()
            || $this->fixedChargesBilledInMonthlySplitIntervals();
    }

    public function invoiceName(): string
    {
        return ($this->invoice_display_name !== null && $this->invoice_display_name !== '')
            ? $this->invoice_display_name
            : $this->name;
    }

    /**
     * NOTE: Method used to compare plan for upgrade / downgrade on a same
     * duration basis. It is not intended to be used directly for
     * billing/invoicing purpose (plan.rb:112-122).
     */
    public function yearlyAmountCents(): int
    {
        if ($this->yearly()) {
            return $this->amount_cents;
        }

        if ($this->monthly()) {
            return $this->amount_cents * 12;
        }

        if ($this->quarterly()) {
            return $this->amount_cents * 4;
        }

        if ($this->semiannual()) {
            return $this->amount_cents * 2;
        }

        return $this->amount_cents * 52;
    }

    // -- Validations ---------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid. Error symbols map through Rails' en.yml
     * activerecord.errors.messages table to the API codes.
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
        }

        if ($this->amount_currency !== null && ! Currencies::valid($this->amount_currency)) {
            $errors['amount_currency'] = ['value_is_invalid'];
        }

        // A blank interval keeps the historical "value_is_invalid" error (the
        // enum used to reject nil as an out-of-range value) rather than a
        // "value_is_mandatory" one (plan.rb:56-58).
        if ($this->interval === null) {
            $errors['interval'] = ['value_is_invalid'];
        } elseif (PlanInterval::tryFrom($this->interval) === null) {
            $errors['interval'] = ['value_is_invalid'];
        }

        if ($this->amount_cents === null) {
            $errors['amount_cents'] = ['value_is_mandatory'];
        }

        if ($this->pay_in_advance !== true && $this->pay_in_advance !== false) {
            $errors['pay_in_advance'] = ['value_is_invalid'];
        }

        // Rails: validate_code_unique — only parent plans are checked, within
        // the organization, among non-discarded parents.
        if ($this->organization_id !== null && $this->parent_id === null && ($this->code ?? '') !== '') {
            $uniqueness = static::query()
                ->whereNull('parent_id')
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

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'interval' => 'integer',
            'amount_cents' => 'integer',
            'trial_period' => 'float',
            'pay_in_advance' => 'boolean',
            'bill_charges_monthly' => 'boolean',
            'pending_deletion' => 'boolean',
            'bill_fixed_charges_monthly' => 'boolean',
        ];
    }

    /**
     * Rails: `interval=` assigns the enum NAME ("monthly"…) and the column
     * stores the integer position. Unmapped values pass through raw so the
     * validation reports them (never silently coerced).
     */
    protected function interval(): Attribute
    {
        return Attribute::set(function ($value) {
            if ($value === null) {
                return null;
            }

            return PlanInterval::fromOption($value) ?? $value;
        });
    }
}
