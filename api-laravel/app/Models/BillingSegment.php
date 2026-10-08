<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use App\Enums\BillingSegmentStatus;
use App\Services\Validators\Currencies;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `billing_segments`. Port of the Rails
 * BillingSegment model (app/models/billing_segment.rb) — a durable priced
 * slice of a contract rate card billing cycle. A whole cycle has one
 * segment; a rate change inside the cycle creates several segments sharing
 * cycle_started_at.
 */
#[Fillable([
    'organization_id',
    'contract_id',
    'customer_id',
    'contract_rate_card_id',
    'invoice_id',
    'rate_card_rate_id',
    'rate_override_id',
    'pricing_unit_id',
    'cycle_started_at',
    'started_at',
    'ended_at',
    'billing_at',
    'rate_properties',
    'currency',
    'proration_ratio',
    'status',
])]
#[Table(name: 'billing_segments')]
class BillingSegment extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    /** Rails: BillingSegment::STATUSES. */
    public const STATUSES = [
        'pending' => 'pending',
        'processing' => 'processing',
        'done' => 'done',
        'failed' => 'failed',
    ];

    /** Rails: MICROSECOND = Rational(1, 1_000_000). */
    public const MICROSECOND = 0.000001;

    protected $attributes = [
        'rate_properties' => '{}',
        'proration_ratio' => '1.0',
        'status' => 'pending',
    ];

    // -- Calendar-window conversions -----------------------------------------

    /**
     * Rails: `inclusive_end(instant)` — schedule windows have exclusive
     * ends; the database overlap constraint uses inclusive ends.
     */
    public static function inclusiveEnd(DateTimeInterface $instant): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::createFromInterface($instant)->subMicroseconds(1);
    }

    /** Rails: `exclusive_end(instant)` — the inverse conversion. */
    public static function exclusiveEnd(DateTimeInterface $instant): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::createFromInterface($instant)->addMicroseconds(1);
    }

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

    /** Rails: `belongs_to :customer, -> { with_discarded }`. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Rails: `belongs_to :contract_rate_card, -> { with_discarded }`. */
    public function contractRateCard(): BelongsTo
    {
        return $this->belongsTo(ContractRateCard::class)->withTrashed();
    }

    /** Rails: `belongs_to :invoice, optional: true`. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Rails: `belongs_to :rate_card_rate, -> { with_discarded }, optional: true`. */
    public function rateCardRate(): BelongsTo
    {
        return $this->belongsTo(RateCardRate::class)->withTrashed();
    }

    /** Rails: `belongs_to :rate_override, -> { with_discarded }, optional: true`. */
    public function rateOverride(): BelongsTo
    {
        return $this->belongsTo(RateOverride::class)->withTrashed();
    }

    /** Rails: `belongs_to :pricing_unit, optional: true`. */
    public function pricingUnit(): BelongsTo
    {
        return $this->belongsTo(PricingUnit::class);
    }

    // -- Validations ----------------------------------------------------------

    /**
     * Port of the Rails validations — `field => [api error codes]`.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // Rails: validates :billing_at/:cycle_started_at/:started_at/:ended_at, presence: true.
        foreach (['billing_at', 'cycle_started_at', 'started_at', 'ended_at'] as $field) {
            if (($this->getAttribute($field) ?? null) === null || $this->getAttribute($field) === '') {
                $errors[$field] = ['value_is_mandatory'];
            }
        }

        // Rails: validates :currency, presence: true, inclusion: {in: currency_list}.
        if (($this->currency ?? '') === '') {
            $errors['currency'] = ['value_is_mandatory'];
        } elseif (! Currencies::valid((string) $this->currency)) {
            $errors['currency'] = ['invalid_currency'];
        }

        // Rails: validates :proration_ratio, numericality: {gte 0, lte 1}.
        $ratio = $this->proration_ratio ?? '1.0';
        if (bccomp((string) $ratio, '0', 10) === -1 || bccomp((string) $ratio, '1', 10) === 1) {
            $errors['proration_ratio'] = ['value_is_out_of_range'];
        }

        // Rails: validate :validate_rate_presence.
        if ($this->rate_card_rate_id === null && $this->rate_override_id === null) {
            $errors['base'] = [...($errors['base'] ?? []), 'rate_card_rate_or_rate_override_required'];
        }

        // Rails: validate :validate_period_bounds (started_at < ended_at; the
        // DB carries the same CHECK).
        if ($this->started_at !== null && $this->ended_at !== null
            && $this->started_at > $this->ended_at) {
            $errors['ended_at'] = ['must_be_after_started_at'];
        }

        // Rails: validate :validate_cycle_bounds (cycle_started_at <= started_at).
        if ($this->cycle_started_at !== null && $this->started_at !== null
            && $this->cycle_started_at > $this->started_at) {
            $errors['cycle_started_at'] = ['must_be_before_started_at'];
        }

        return $errors;
    }

    // -- Domain methods -------------------------------------------------------

    /** Rails: `rate` — a phase override prices the segment, else the card's rate. */
    public function rate(): RateCardRate|RateOverride
    {
        return $this->rateOverride ?? $this->rateCardRate;
    }

    /** Rails: `target_key` — the consumer's bucket key for this segment. */
    public function targetKey(): string
    {
        // Rails: contract_rate_card.product.target_key == "product-#{product.id}".
        $productId = $this->contractRateCard->rateCard->product_id;

        return "contract-{$this->contract_id}-product-{$productId}";
    }

    /** Rails: `duration_in_days` — whole billable days of the segment window. */
    public function durationInDays(): int
    {
        return \App\Services\Billing\Days::between(
            $this->started_at,
            self::exclusiveEnd($this->ended_at),
            $this->customer->applicableTimezone(),
        );
    }

    /**
     * Rails: `elapsed_period_ratio(at:)` — elapsed progress is independent of
     * the stored service-price proration_ratio.
     */
    public function elapsedPeriodRatio(?DateTimeInterface $at = null): float
    {
        $timezone = $this->customer->applicableTimezone();
        $at ??= now();
        $atLocal = \Carbon\CarbonImmutable::parse($at)->setTimezone($timezone);

        return Billing\ElapsedPeriodRatio::calculate(
            fromDate: \Carbon\CarbonImmutable::parse($this->started_at)->setTimezone($timezone)->startOfDay(),
            toDate: \Carbon\CarbonImmutable::parse($this->ended_at)->setTimezone($timezone)->startOfDay(),
            currentDate: $atLocal->startOfDay(),
            durationInDays: $this->durationInDays(),
        );
    }

    /** Rails: `pricing_unit_conversion_rate` — the override's, else the rate's applied one. */
    public function pricingUnitConversionRate(): ?string
    {
        if ($this->rateOverride !== null) {
            return $this->rateOverride->pricing_unit_conversion_rate;
        }

        return $this->rateCardRate?->applied_pricing_unit_conversion_rate;
    }

    /** Rails: `min_amount_cents` — the override's, else the rate's. */
    public function minAmountCents(): int
    {
        if ($this->rateOverride !== null) {
            return (int) $this->rateOverride->min_amount_cents;
        }

        return (int) ($this->rateCardRate?->min_amount_cents ?? 0);
    }

    /**
     * Rails: `prorated_min_amount_cents` — the prorated minimum in
     * pricing-unit cents when configured, otherwise fiat cents.
     */
    public function proratedMinAmountCents(): string
    {
        $minimum = \App\Support\MoneyMath::mul(
            (string) $this->minAmountCents(),
            (string) $this->proration_ratio,
        );

        if ($this->pricingUnit !== null) {
            // Rails: minimum / fiat_subunit / conversion_rate * unit_subunit.
            $fiatSubunit = (string) \App\Support\Currency::subunitToUnit((string) $this->currency);
            $unitSubunit = (string) $this->pricingUnit->subunitToUnit();
            $conversionRate = $this->pricingUnitConversionRate() ?? '1';

            return \App\Support\MoneyMath::fdiv(
                \App\Support\MoneyMath::mul(
                    \App\Support\MoneyMath::fdiv($minimum, $fiatSubunit),
                    \App\Support\MoneyMath::fdiv($unitSubunit, $conversionRate),
                ),
                '1',
            );
        }

        return $minimum;
    }

    // -- Scopes ---------------------------------------------------------------

    /**
     * Rails: scope `awaiting_invoicing` — what the periodic consumer still
     * owes an invoice for. One name for both ends of the pipe: the clock
     * selects customers by it and the consumer selects their segments by it,
     * so neither can drift into offering work the other will not do.
     *
     * Metered usage billed in advance is priced per event rather than on the
     * tick, so its segments are never invoiced here and never leave their
     * state. Selecting them would enqueue that customer every hour for a run
     * with nothing to do.
     */
    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function awaitingInvoicing($query)
    {
        return $query
            ->select('billing_segments.*')
            ->join('contract_rate_cards', 'contract_rate_cards.id', '=', 'billing_segments.contract_rate_card_id')
            ->join('rate_cards', 'rate_cards.id', '=', 'contract_rate_cards.rate_card_id')
            ->join('products', 'products.id', '=', 'rate_cards.product_id')
            ->whereIn('billing_segments.status', [self::STATUSES['pending'], self::STATUSES['processing']])
            ->where(function ($q): void {
                $q->where('products.product_type', '!=', Product::PRODUCT_TYPES['metered'])
                    ->orWhere('rate_cards.billing_timing', '!=', RateCard::BILLING_TIMINGS['advance']);
            });
    }

    /** Rails bigint / decimal / jsonb columns cast consistently. */
    protected function casts(): array
    {
        return [
            'billing_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'cycle_started_at' => 'datetime',
            'proration_ratio' => 'string',
            'rate_properties' => 'array',
            'status' => BillingSegmentStatus::class,
        ];
    }
}
