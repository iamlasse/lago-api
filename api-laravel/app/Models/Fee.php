<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeeType;
use App\Support\MoneyMath;
use App\Enums\FeePaymentStatus;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Port of Rails' Fee (app/models/fee.rb) for the billing pipeline.
 * Not ported (TODO(port)): presentation_breakdowns, pricing_unit_usage,
 * wallet transactions, payment refinements.
 */
#[Fillable([
    'invoice_id',
    'charge_id',
    'subscription_id',
    'amount_cents',
    'amount_currency',
    'taxes_amount_cents',
    'taxes_rate',
    'units',
    'applied_add_on_id',
    'properties',
    'fee_type',
    'invoiceable_type',
    'invoiceable_id',
    'events_count',
    'group_id',
    'pay_in_advance_event_id',
    'payment_status',
    'succeeded_at',
    'failed_at',
    'refunded_at',
    'true_up_parent_fee_id',
    'add_on_id',
    'description',
    'unit_amount_cents',
    'pay_in_advance',
    'precise_coupons_amount_cents',
    'total_aggregated_units',
    'invoice_display_name',
    'precise_unit_amount',
    'amount_details',
    'charge_filter_id',
    'grouped_by',
    'pay_in_advance_event_transaction_id',
    'precise_amount_cents',
    'taxes_precise_amount_cents',
    'taxes_base_rate',
    'organization_id',
    'billing_entity_id',
    'precise_credit_notes_amount_cents',
    'fixed_charge_id',
    'duplicated_in_advance',
    'original_fee_id',
    'rate_card_rate_id',
    'rate_override_id',
    'product_filter_id',
    'contract_id',
    'contract_rate_card_id',
])]
#[Table(name: 'fees')]
class Fee extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** Port of `has_many :applied_taxes, class_name: "Fee::AppliedTax"` (fees_taxes). */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(FeeAppliedTax::class);
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'fees_taxes', 'fee_id', 'tax_id');
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }

    public function fixedCharge(): BelongsTo
    {
        return $this->belongsTo(FixedCharge::class, 'fixed_charge_id');
    }

    public function trueUpParentFee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'true_up_parent_fee_id');
    }

    // -- Type predicates -------------------------------------------------------

    public function typeEnum(): ?FeeType
    {
        return $this->fee_type instanceof FeeType ? $this->fee_type : ($this->fee_type === null ? null : FeeType::tryFrom((int) $this->fee_type));
    }

    public function isCharge(): bool
    {
        return $this->typeEnum() === FeeType::Charge;
    }

    public function isSubscription(): bool
    {
        return $this->typeEnum() === FeeType::Subscription;
    }

    public function paymentStatusEnum(): ?FeePaymentStatus
    {
        // BUGFIX(port): payment_status is enum-cast — the raw value may
        // already be the enum (typeEnum() has the same guard); (int) on it
        // throws "could not be converted to int".
        return $this->payment_status === null
            ? null
            : ($this->payment_status instanceof FeePaymentStatus
                ? $this->payment_status
                : FeePaymentStatus::tryFrom((int) $this->payment_status));
    }

    // -- Rails domain methods --------------------------------------------------

    /**
     * Port of `sub_total_excluding_taxes_amount_cents`:
     * amount_cents - precise_coupons_amount_cents (a precise decimal).
     */
    public function subTotalExcludingTaxesAmountCents(): string
    {
        return MoneyMath::sub((int) $this->amount_cents, (string) $this->precise_coupons_amount_cents);
    }

    /**
     * Port of `sub_total_excluding_taxes_precise_amount_cents`:
     * precise_amount_cents - precise_coupons_amount_cents.
     */
    public function subTotalExcludingTaxesPreciseAmountCents(): string
    {
        return MoneyMath::sub((string) $this->precise_amount_cents, (string) $this->precise_coupons_amount_cents);
    }

    /**
     * Port of `compute_precise_credit_amount_cents(credit_amount, base_amount_cents)`
     * — distributes a coupon credit over the fee pro-rata to its remaining
     * amount; returns a precise decimal string.
     */
    public function computePreciseCreditAmountCents(int $creditAmount, int $baseAmountCents): string
    {
        if ($baseAmountCents === 0) {
            return '0';
        }

        $remaining = MoneyMath::sub((int) $this->amount_cents, (string) $this->precise_coupons_amount_cents);

        return MoneyMath::mul((string) $creditAmount, MoneyMath::fdiv($remaining, (string) $baseAmountCents));
    }

    // -- Scopes (Rails enum scopes) -------------------------------------------
    // Legacy scopeXyz() form: #[Scope] subscription()/charge() would collide
    // with the subscription()/charge() relations above.

    protected function scopeSubscription(Builder $query): Builder
    {
        return $query->where('fee_type', FeeType::Subscription->value);
    }

    protected function scopeCharge(Builder $query): Builder
    {
        return $query->where('fee_type', FeeType::Charge->value);
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'taxes_amount_cents' => 'integer',
            'taxes_rate' => 'float',
            'units' => [BcNumeric::class, 'scale' => 0],
            'properties' => 'array',
            'fee_type' => FeeType::class,
            'events_count' => 'integer',
            'payment_status' => FeePaymentStatus::class,
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'unit_amount_cents' => 'integer',
            'pay_in_advance' => 'boolean',
            'precise_coupons_amount_cents' => [BcNumeric::class, 'scale' => 5],
            'total_aggregated_units' => [BcNumeric::class, 'scale' => 0],
            'precise_unit_amount' => [BcNumeric::class, 'scale' => 15],
            'amount_details' => 'array',
            'grouped_by' => 'array',
            'precise_amount_cents' => [BcNumeric::class, 'scale' => 15],
            'taxes_precise_amount_cents' => [BcNumeric::class, 'scale' => 15],
            'taxes_base_rate' => 'float',
            'precise_credit_notes_amount_cents' => [BcNumeric::class, 'scale' => 5],
            'duplicated_in_advance' => 'boolean',
        ];
    }
}
