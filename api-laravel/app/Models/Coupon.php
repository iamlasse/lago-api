<?php

declare(strict_types=1);

namespace App\Models;

use BackedEnum;
use App\Enums\CouponType;
use App\Enums\CouponStatus;
use App\Enums\CouponFrequency;
use App\Enums\CouponExpiration;
use App\Models\Casts\BcNumeric;
use Illuminate\Support\Collection;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Coupon (app/models/coupon.rb).
 *
 * Limited plans / billable metrics resolve through the coupon_targets join
 * (App\Models\CouponTarget).
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'status',
    'terminated_at',
    'amount_cents',
    'amount_currency',
    'expiration',
    'coupon_type',
    'percentage_rate',
    'frequency',
    'frequency_duration',
    'expiration_at',
    'reusable',
    'limited_plans',
    'limited_billable_metrics',
    'description',
])]
#[Table(name: 'coupons')]
class Coupon extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Rails enums (status / expiration / coupon_type / frequency) carry
     * `validate: true` — an unknown name would raise ArgumentError; we null
     * it and record the assignment for the inclusion validation instead.
     *
     * @var array<string, true>
     */
    protected array $invalidEnumAssignments = [];

    /**
     * Column defaults (Rails hydrates them onto new records): status
     * "active", coupon_type "fixed_amount", frequency "once", reusable
     * true, no limitations.
     */
    protected $attributes = [
        'status' => CouponStatus::Active->value,
        'coupon_type' => CouponType::FixedAmount->value,
        'frequency' => CouponFrequency::Once->value,
        'reusable' => true,
        'limited_plans' => false,
        'limited_billable_metrics' => false,
    ];

    // -- Relationships ---------------------------------------------------------

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `has_many :applied_coupons`. */
    public function appliedCoupons(): HasMany
    {
        return $this->hasMany(AppliedCoupon::class);
    }

    /** Rails: `has_many :customers, through: :applied_coupons`. */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'applied_coupons', 'coupon_id', 'customer_id');
    }

    /** Rails: `has_many :coupon_targets` (default_scope kept — not discarded). */
    public function couponTargets(): HasMany
    {
        return $this->hasMany(CouponTarget::class);
    }

    /** Rails: `has_many :plans, through: :coupon_targets`. */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'coupon_targets', 'coupon_id', 'plan_id')
            ->whereNull('coupon_targets.deleted_at');
    }

    /** Rails: `has_many :billable_metrics, through: :coupon_targets`. */
    public function billableMetrics(): BelongsToMany
    {
        return $this->belongsToMany(BillableMetric::class, 'coupon_targets', 'coupon_id', 'billable_metric_id')
            ->whereNull('coupon_targets.deleted_at');
    }

    // -- Enum accessors ---------------------------------------------------------

    public function typeEnum(): ?CouponType
    {
        return $this->coupon_type instanceof CouponType ? $this->coupon_type : ($this->coupon_type === null ? null : CouponType::tryFrom((int) $this->coupon_type));
    }

    public function expirationEnum(): ?CouponExpiration
    {
        return $this->expiration instanceof CouponExpiration ? $this->expiration : ($this->expiration === null ? null : CouponExpiration::tryFrom((int) $this->expiration));
    }

    public function frequencyEnum(): ?CouponFrequency
    {
        return $this->frequency instanceof CouponFrequency ? $this->frequency : ($this->frequency === null ? null : CouponFrequency::tryFrom((int) $this->frequency));
    }

    /** Rails: `fixed_amount?` — coupon_type == :fixed_amount. */
    public function fixedAmount(): bool
    {
        return $this->typeEnum() === CouponType::FixedAmount;
    }

    /** Rails: `percentage?` — coupon_type == :percentage. */
    public function percentage(): bool
    {
        return $this->typeEnum() === CouponType::Percentage;
    }

    /** Rails: `forever?` on the frequency. */
    public function forever(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Forever;
    }

    /** Rails: `terminated?` on the status. */
    public function isTerminated(): bool
    {
        return $this->statusEnum() === CouponStatus::Terminated;
    }

    public function statusEnum(): ?CouponStatus
    {
        return $this->status instanceof CouponStatus ? $this->status : ($this->status === null ? null : CouponStatus::tryFrom((int) $this->status));
    }

    // -- Domain methods (ports of the Rails instance methods) -------------------

    /** Port of `mark_as_terminated!(timestamp = Time.zone.now)`. */
    public function markAsTerminated(?\Carbon\CarbonInterface $timestamp = null): void
    {
        $this->terminated_at ??= $timestamp ?? now();

        $this->status = CouponStatus::Terminated;
        $this->save();
    }

    /**
     * Port of `parent_and_overriden_plans` — the coupon's limited plans plus
     * their children (overrides), via the coupon_targets join.
     */
    public function parentAndOverridenPlans(): Collection
    {
        $plans = $this->plans()->get();

        return $plans->concat($plans->flatMap(static fn (Plan $plan) => $plan->children))->values();
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
        } else {
            // Rails: uniqueness {conditions: -> { where(deleted_at: nil) },
            // scope: :organization_id} (backed by a partial unique index).
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

        $this->validateEnumInclusion('status', CouponStatus::class, $errors);
        $this->validateEnumInclusion('expiration', CouponExpiration::class, $errors);
        $this->validateEnumInclusion('coupon_type', CouponType::class, $errors);
        $this->validateEnumInclusion('frequency', CouponFrequency::class, $errors);

        $couponType = $this->typeEnum();

        if ($couponType === CouponType::FixedAmount && $this->amount_cents === null) {
            $errors['amount_cents'] = ['value_is_mandatory'];
        }

        if ($this->amount_cents !== null && (int) $this->amount_cents <= 0) {
            $errors['amount_cents'] = ['value_is_out_of_range'];
        }

        if ($couponType === CouponType::FixedAmount && ($this->amount_currency ?? '') === '') {
            $errors['amount_currency'] = ['value_is_mandatory'];
        }

        if ($this->amount_currency !== null && ! Currencies::valid($this->amount_currency)) {
            $errors['amount_currency'] = ['value_is_invalid'];
        }

        if ($couponType === CouponType::Percentage && $this->percentage_rate === null) {
            $errors['percentage_rate'] = ['value_is_mandatory'];
        }

        if ($this->frequencyEnum() === CouponFrequency::Recurring) {
            if ($this->frequency_duration === null) {
                $errors['frequency_duration'] = ['value_is_mandatory'];
            } elseif ((int) $this->frequency_duration <= 0) {
                $errors['frequency_duration'] = ['value_is_out_of_range'];
            }
        }

        // Rails: validates :reusable, exclusion: [nil] — the attribute must
        // be explicitly provided.
        if ($this->raw('reusable') === null) {
            $errors['reusable'] = ['value_is_reserved'];
        }

        return $errors;
    }

    // -- Scopes ------------------------------------------------------------------

    /** Rails: `default_scope -> { kept }` — the SoftDeletes global scope. */

    /** Rails: `scope :active`. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', CouponStatus::Active->value);
    }

    /** Rails: `scope :time_limit`. */
    #[Scope]
    protected function timeLimit(Builder $query): Builder
    {
        return $query->where('expiration', CouponExpiration::TimeLimit->value);
    }

    /** Rails: `scope :expired`. */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->where('expiration_at', '<', now());
    }

    /** Rails: `scope :order_by_status_and_expiration`. */
    #[Scope]
    protected function orderByStatusAndExpiration(Builder $query): Builder
    {
        return $query->orderByRaw('coupons.status ASC, coupons.expiration ASC, coupons.expiration_at ASC');
    }

    // -- Enum assignment mutators -------------------------------------------------

    /** Rails: `enum :coupon_type, validate: true`. */
    protected function couponType(): Attribute
    {
        return $this->enumAttribute(CouponType::class, 'coupon_type');
    }

    /** Rails: `enum :expiration, validate: true`. */
    protected function expiration(): Attribute
    {
        return $this->enumAttribute(CouponExpiration::class, 'expiration');
    }

    /** Rails: `enum :frequency, validate: true`. */
    protected function frequency(): Attribute
    {
        return $this->enumAttribute(CouponFrequency::class, 'frequency');
    }

    /** Rails: `enum :status, validate: true`. */
    protected function status(): Attribute
    {
        return $this->enumAttribute(CouponStatus::class, 'status');
    }

    /**
     * The raw (unmapped) column value — the enum position — for new and
     * loaded records alike.
     */
    protected function raw(string $key): mixed
    {
        return $this->getAttributes()[$key] ?? null;
    }

    protected function casts(): array
    {
        return [
            'status' => CouponStatus::class,
            'terminated_at' => 'datetime',
            'amount_cents' => 'integer',
            'expiration' => CouponExpiration::class,
            'coupon_type' => CouponType::class,
            'percentage_rate' => [BcNumeric::class, 'scale' => 5],
            'frequency' => CouponFrequency::class,
            'frequency_duration' => 'integer',
            'expiration_at' => 'datetime',
            'reusable' => 'boolean',
            'limited_plans' => 'boolean',
            'limited_billable_metrics' => 'boolean',
        ];
    }

    /**
     * Maps Rails enum names / backing ints / enum instances onto the stored
     * column position; unknown names are nulled and recorded for the
     * inclusion validation.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    protected function enumAttribute(string $enumClass, string $field): Attribute
    {
        return Attribute::set(function (mixed $value) use ($enumClass, $field): ?int {
            if ($value === null) {
                return null;
            }

            if ($value instanceof $enumClass) {
                return $value->value;
            }

            $mapped = $enumClass::fromOption($value);

            if ($mapped === null) {
                $this->invalidEnumAssignments[$field] = true;

                return null;
            }

            return $mapped;
        });
    }

    /**
     * Rails `enum validate: true` inclusion check on the mapped column value.
     *
     * @param  class-string<BackedEnum>  $enumClass
     * @param  array<string, list<string>>  $errors
     */
    private function validateEnumInclusion(string $field, string $enumClass, array &$errors): void
    {
        if (isset($this->invalidEnumAssignments[$field])) {
            $errors[$field] = ['value_is_invalid'];

            return;
        }

        $raw = $this->raw($field);

        if ($raw !== null && $enumClass::tryFrom((int) $raw) === null) {
            $errors[$field] = ['value_is_invalid'];
        }
    }
}
