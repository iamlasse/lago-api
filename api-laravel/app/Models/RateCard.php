<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RateCardBillingTiming;
use App\Enums\RateCardRegroupPaidFees;
use App\Services\Validators\Currencies;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `rate_cards`. Port of the Rails RateCard model
 * (app/models/rate_card.rb): the catalog price card on a product, with its
 * append-only rate timeline.
 */
#[Fillable([
    'organization_id',
    'product_id',
    'product_filter_id',
    'code',
    'name',
    'description',
    'currency',
    'billing_timing',
    'proration',
    'display_on_invoice',
    'regroup_paid_fees',
    'applied_pricing_unit_code',
])]
#[Table(name: 'rate_cards')]
class RateCard extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern). */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    /** Rails: BILLING_TIMINGS. */
    public const BILLING_TIMINGS = ['arrears' => 'arrears', 'advance' => 'advance'];

    /**
     * Rails: REGROUP_PAID_FEES — nil means the paid fee stays standalone;
     * `invoice` folds it into the invoice. The schema also carries `none`.
     */
    public const REGROUP_PAID_FEES = ['invoice' => 'invoice', 'none' => 'none'];

    /** NOT NULL columns with DB defaults, mirrored on new instances. */
    protected $attributes = [
        'billing_timing' => 'arrears',
        'proration' => false,
        'display_on_invoice' => true,
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :product`. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Rails: `belongs_to :product_filter, optional: true`. */
    public function productFilter(): BelongsTo
    {
        return $this->belongsTo(ProductFilter::class);
    }

    /** Rails: `has_many :rates, class_name: "RateCardRate"`. */
    public function rates(): HasMany
    {
        return $this->hasMany(RateCardRate::class);
    }

    /** Rails: `has_many :plan_applied_rate_cards, class_name: "PlanRateCard"`. */
    public function planAppliedRateCards(): HasMany
    {
        return $this->hasMany(PlanRateCard::class);
    }

    /** Rails: `has_many :contract_applied_rate_cards, class_name: "ContractRateCard"`. */
    public function contractAppliedRateCards(): HasMany
    {
        return $this->hasMany(ContractRateCard::class);
    }

    /** Rails: `has_many :applied_taxes, class_name: "RateCard::AppliedTax", dependent: :destroy`. */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(RateCardAppliedTax::class);
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'rate_cards_taxes', 'rate_card_id', 'tax_id')
            ->withPivot('organization_id');
    }

    // -- Enum helpers -----------------------------------------------------------

    /** The raw PG enum label, readable on unsaved records too. */
    public function rawBillingTiming(): string
    {
        return (string) ($this->getAttributes()['billing_timing'] ?? $this->getRawOriginal('billing_timing'));
    }

    /** Rails: `arrears?`. */
    public function arrears(): bool
    {
        return $this->rawBillingTiming() === RateCardBillingTiming::Arrears->value;
    }

    /** Rails: `advance?`. */
    public function advance(): bool
    {
        return $this->rawBillingTiming() === RateCardBillingTiming::Advance->value;
    }

    /** Rails: `display_on_invoice?`. */
    public function displayOnInvoice(): bool
    {
        return (bool) $this->display_on_invoice;
    }

    /** Rails: `proration?`. */
    public function proration(): bool
    {
        return (bool) $this->proration;
    }

    /** Rails: `regroup_paid_fees_invoice?` (prefix: true enum). */
    public function regroupPaidFeesInvoice(): bool
    {
        $value = $this->getAttributes()['regroup_paid_fees'] ?? $this->getRawOriginal('regroup_paid_fees');

        return (string) ($value ?? '') === RateCardRegroupPaidFees::Invoice->value;
    }

    // -- Domain methods (ports of the Rails instance methods) ------------------

    /** Rails: CatalogAttachable — cards attached to a plan or a contract freeze the card. */
    public function attachedToPlanOrSubscription(): bool
    {
        return $this->planAppliedRateCards()->exists() || $this->contractAppliedRateCards()->exists();
    }

    /**
     * Rails: `attached_to_subscriptions?` — the card bills someone once it
     * belongs to a catalog plan with contracts or sits on a contract; from
     * then on the billed timeline freezes and changes go through appends.
     */
    public function attachedToSubscriptions(): bool
    {
        return $this->contractAppliedRateCards()->exists()
            || Contract::query()
                ->whereIn('catalog_plan_id', $this->planAppliedRateCards()->select('catalog_plan_id'))
                ->exists();
    }

    /** Rails: `ordered_rates` — `rates.order(:effective_from)`. */
    public function orderedRates(): HasMany
    {
        return $this->rates()->orderBy('effective_from');
    }

    /**
     * Rails: `active_rate` — the latest effective rate; later rates are
     * pending and earlier ones have been superseded.
     */
    public function activeRate(): ?RateCardRate
    {
        /** @var RateCardRate|null */
        return $this->rates()
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->first();
    }

    /** Rails: `invoice_name`. */
    public function invoiceName(): string
    {
        return ($this->invoice_display_name ?: $this->name) ?? '';
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $this->validateCode($errors);

        // Rails: presence + inclusion (allow_nil keeps a missing currency on
        // the presence error only; a provided-but-unknown currency adds the
        // inclusion error).
        $currency = $this->currency;
        if (($currency ?? '') === '') {
            $errors['currency'] = ['value_is_mandatory'];
        } elseif (! in_array((string) $currency, Currencies::list(), true)) {
            $errors['currency'] = ['value_is_invalid'];
        }

        $billingTiming = $this->rawBillingTiming();
        if ($billingTiming !== '' && ! in_array($billingTiming, self::BILLING_TIMINGS, true)) {
            $errors['billing_timing'] = ['value_is_invalid'];
        }

        $regroupPaidFees = $this->getAttributes()['regroup_paid_fees'] ?? $this->getRawOriginal('regroup_paid_fees');
        if ($regroupPaidFees !== null && ! in_array((string) $regroupPaidFees, self::REGROUP_PAID_FEES, true)) {
            $errors['regroup_paid_fees'] = ['value_is_invalid'];
        }

        $this->validateFilterBelongsToProduct($errors);
        $this->validateDisplayOnInvoice($errors);
        $this->validateProration($errors);
        $this->validateRegroupPaidFees($errors);

        return $errors;
    }

    /** @param array<string, list<string>> $errors */
    protected function validateCode(array &$errors): void
    {
        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];

            return;
        }

        if (preg_match(self::CODE_FORMAT, (string) $this->code) !== 1) {
            $errors['code'] = ['value_is_invalid'];

            return;
        }

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

    /** Rails: `validate_filter_belongs_to_product` — a filter-scoped card must price its own item. */
    protected function validateFilterBelongsToProduct(array &$errors): void
    {
        $filterId = $this->product_filter_id;

        if ($filterId === null || $this->product_id === null) {
            return;
        }

        $belongsTo = ProductFilter::query()->whereKey($filterId)->where('product_id', $this->product_id)->exists();

        if (! $belongsTo) {
            $errors['product_filter'] = ['does_not_belong_to_product'];
        }
    }

    /**
     * Rails: `validate_display_on_invoice` — a fixed product bills one fee
     * per period, so its line must always show; otherwise the flag only
     * makes sense for metered on advance.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateDisplayOnInvoice(array &$errors): void
    {
        if ($this->displayOnInvoice()) {
            return;
        }

        $product = $this->product_id !== null ? Product::query()->find($this->product_id) : null;

        if ($product !== null && $product->fixed()) {
            $errors['display_on_invoice'] = ['not_allowed_for_product_type'];
        } elseif ($this->arrears()) {
            $errors['display_on_invoice'] = ['not_allowed_for_billing_timing'];
        }
    }

    /**
     * Rails: `validate_proration` — metered proration spreads a recurring
     * quantity across the period, so it needs a recurring metric and not
     * weighted_sum, which prorates by design.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateProration(array &$errors): void
    {
        if (! $this->proration()) {
            return;
        }

        $product = $this->product_id !== null ? Product::query()->find($this->product_id) : null;
        if ($product === null || ! $product->metered()) {
            return;
        }

        $metric = $product->billableMetric;
        if ($metric === null) {
            return;
        }

        if (! (bool) $metric->recurring) {
            $errors['proration'] = ['requires_recurring_metric'];
        }

        if ($metric->weightedSumAgg()) {
            $errors['proration'] = [...($errors['proration'] ?? []), 'not_allowed_for_aggregation_type'];
        }
    }

    /**
     * Rails: `validate_regroup_paid_fees` — paid-fee regrouping only exists
     * for advance fees kept off the invoice; the pairing checks apply only
     * to the `invoice` value, so an invalid value fails on its inclusion
     * error alone.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateRegroupPaidFees(array &$errors): void
    {
        if (! $this->regroupPaidFeesInvoice()) {
            return;
        }

        if (! $this->advance()) {
            $errors['regroup_paid_fees'] = ['not_allowed_for_billing_timing'];
        }

        if ($this->displayOnInvoice()) {
            $errors['regroup_paid_fees'] = [
                ...($errors['regroup_paid_fees'] ?? []),
                'not_allowed_with_display_on_invoice',
            ];
        }
    }

    protected function casts(): array
    {
        return [
            'billing_timing' => RateCardBillingTiming::class,
            'proration' => 'boolean',
            'display_on_invoice' => 'boolean',
            'regroup_paid_fees' => RateCardRegroupPaidFees::class,
        ];
    }
}
