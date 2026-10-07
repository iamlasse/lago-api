<?php

declare(strict_types=1);

namespace App\Models;

use BackedEnum;
use App\Enums\InvoiceStatus;
use App\Enums\CouponFrequency;
use App\Models\Casts\BcNumeric;
use App\Enums\AppliedCouponStatus;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' AppliedCoupon (app/models/applied_coupon.rb).
 */
#[Fillable([
    'coupon_id',
    'customer_id',
    'status',
    'amount_cents',
    'amount_currency',
    'terminated_at',
    'percentage_rate',
    'frequency',
    'frequency_duration',
    'frequency_duration_remaining',
    'organization_id',
])]
#[Table(name: 'applied_coupons')]
class AppliedCoupon extends BaseModel
{
    use HasFactory;

    /**
     * Rails' AppliedCoupon.frequency enum carries no `validate: true` — an
     * unknown name would raise; we null it and record the assignment for the
     * inclusion validation instead.
     *
     * @var array<string, true>
     */
    protected array $invalidEnumAssignments = [];

    /**
     * Column defaults (Rails hydrates them onto new records): status
     * "active", frequency "once".
     */
    protected $attributes = [
        'status' => AppliedCouponStatus::Active->value,
        'frequency' => CouponFrequency::Once->value,
    ];

    // -- Relationships ---------------------------------------------------------

    /**
     * Rails: `belongs_to :coupon, -> { with_discarded }` — the parent coupon
     * stays readable after it has been discarded.
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `has_many :credits`. */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    // -- Enum accessors ---------------------------------------------------------

    public function statusEnum(): ?AppliedCouponStatus
    {
        return $this->status instanceof AppliedCouponStatus ? $this->status : ($this->status === null ? null : AppliedCouponStatus::tryFrom((int) $this->status));
    }

    public function frequencyEnum(): ?CouponFrequency
    {
        return $this->frequency instanceof CouponFrequency ? $this->frequency : ($this->frequency === null ? null : CouponFrequency::tryFrom((int) $this->frequency));
    }

    public function isActive(): bool
    {
        return $this->statusEnum() === AppliedCouponStatus::Active;
    }

    /** Rails: `terminated?` on the status. */
    public function isTerminated(): bool
    {
        return $this->statusEnum() === AppliedCouponStatus::Terminated;
    }

    /** Rails: `once?`. */
    public function once(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Once;
    }

    /** Rails: `recurring?`. */
    public function recurring(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Recurring;
    }

    /** Rails: `forever?`. */
    public function forever(): bool
    {
        return $this->frequencyEnum() === CouponFrequency::Forever;
    }

    public function percentage(): bool
    {
        return $this->coupon->percentage();
    }

    // -- Domain methods (ports of the Rails instance methods) -------------------

    /**
     * Port of `remaining_amount` — for `once` fixed-amount coupons the
     * amount not yet consumed by previous invoices (credits whose invoice
     * is not voided/closed/deleted — Rails' `credits.active`).
     */
    public function remainingAmount(): int
    {
        return (int) $this->amount_cents - $this->activeCreditsAmountCents();
    }

    /** The summed amount_cents of the coupon's active credits. */
    public function activeCreditsAmountCents(): int
    {
        return (int) Credit::query()
            ->join('invoices', 'invoices.id', '=', 'credits.invoice_id')
            ->where('credits.applied_coupon_id', $this->id)
            ->whereNotIn('invoices.status', [
                InvoiceStatus::Voided->value,
                InvoiceStatus::Closed->value,
                InvoiceStatus::Deleted->value,
            ])
            ->sum('credits.amount_cents');
    }

    /** Port of `mark_as_terminated!(timestamp = Time.zone.now)`. */
    public function markAsTerminated(?\Carbon\CarbonInterface $timestamp = null): void
    {
        $this->terminated_at ??= $timestamp ?? now();

        $this->status = AppliedCouponStatus::Terminated;
        $this->save();
    }

    // -- Validations ---------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if ($this->amount_cents !== null && (int) $this->amount_cents < 0) {
            $errors['amount_cents'] = ['value_is_out_of_range'];
        }

        if ($this->amount_currency !== null && ! Currencies::valid($this->amount_currency)) {
            $errors['amount_currency'] = ['value_is_invalid'];
        }

        if (isset($this->invalidEnumAssignments['frequency'])) {
            $errors['frequency'] = ['value_is_invalid'];
        }

        if ($this->recurring()) {
            if ($this->frequency_duration === null) {
                $errors['frequency_duration'] = ['value_is_mandatory'];
            } elseif ((int) $this->frequency_duration <= 0) {
                $errors['frequency_duration'] = ['value_is_out_of_range'];
            }

            if ($this->frequency_duration_remaining === null) {
                $errors['frequency_duration_remaining'] = ['value_is_mandatory'];
            } elseif ((int) $this->frequency_duration_remaining < 0) {
                $errors['frequency_duration_remaining'] = ['value_is_out_of_range'];
            }
        }

        return $errors;
    }

    // -- Scopes ------------------------------------------------------------------

    /** Port of `scope :active`. */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('applied_coupons.status', AppliedCouponStatus::Active->value);
    }

    // -- Enum assignment mutators -------------------------------------------------

    /** Rails: `enum :frequency`. */
    protected function frequency(): Attribute
    {
        return $this->enumAttribute(CouponFrequency::class, 'frequency');
    }

    /** Rails: `enum :status`. */
    protected function status(): Attribute
    {
        return $this->enumAttribute(AppliedCouponStatus::class, 'status');
    }

    protected function casts(): array
    {
        return [
            'status' => AppliedCouponStatus::class,
            'amount_cents' => 'integer',
            'terminated_at' => 'datetime',
            'percentage_rate' => [BcNumeric::class, 'scale' => 5],
            'frequency' => CouponFrequency::class,
            'frequency_duration' => 'integer',
            'frequency_duration_remaining' => 'integer',
        ];
    }

    /**
     * Maps Rails enum names / backing ints / enum instances onto the stored
     * column position.
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
}
