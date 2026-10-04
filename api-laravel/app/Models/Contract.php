<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `contracts`. Port of the Rails Contract model
 * (app/models/contract.rb) — the product-catalog runtime object: what a
 * customer signed. It prices through a catalog plan, directly attached rate
 * cards, or both. Legacy billing keeps its own `subscriptions` table; the
 * two engines never share rows.
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'external_id',
    'name',
    'status',
    'billing_time',
    'billing_anchor_date',
    'started_at',
    'ended_at',
    'terminated_at',
    'canceled_at',
    'catalog_plan_id',
    'billing_entity_id',
    'payment_method_id',
    'purchase_order_number',
    'consolidate_invoice',
    'payment_method_type',
])]
#[Table(name: 'contracts')]
class Contract extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;

    /** Rails: STATUSES. */
    public const STATUSES = [
        'pending' => 'pending',
        'active' => 'active',
        'terminated' => 'terminated',
        'canceled' => 'canceled',
    ];

    /** Rails: BILLING_TIMES. */
    public const BILLING_TIMES = ['calendar' => 'calendar', 'anniversary' => 'anniversary'];

    /** Rails: PAYMENT_METHOD_TYPES (enum with prefix: true). */
    public const PAYMENT_METHOD_TYPES = ['provider' => 'provider', 'manual' => 'manual'];

    /** Rails: LIVE_STATUSES. */
    public const LIVE_STATUSES = ['pending', 'active'];

    /**
     * Rails: BILLABLE_STATUSES — which contracts the billing clock may
     * produce segments for. A terminated contract's final arrears period is
     * the termination path's, so it is not billable from the clock.
     */
    public const BILLABLE_STATUSES = ['active'];

    /** NOT NULL columns with DB defaults, mirrored on new instances. */
    protected $attributes = [
        'status' => 'pending',
        'billing_time' => 'calendar',
        'consolidate_invoice' => true,
        'payment_method_type' => 'provider',
    ];

    /**
     * Rails: `Contract.live_by_external_id` — the live contract for an
     * external id. The pending replacement is preferred over the active
     * sibling (the replacement being authored is the target every consumer
     * wants); started_at/created_at break ties deterministically.
     */
    public static function liveByExternalId(string $externalId, ?string $organizationId = null): ?self
    {
        /** @var self|null */
        return static::query()
            ->live()
            ->when($organizationId !== null, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->where('external_id', $externalId)
            ->orderByRaw("status = 'pending' DESC")
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Rails: `Contract.terminatable_by_external_id` — termination ends the
     * agreement in force, so it prefers the ACTIVE contract over a pending
     * replacement (the reverse of liveByExternalId). Ending the active row
     * leaves the future replacement to start on its own; with no active
     * sibling the pending row is the one to cancel.
     *
     * The lookup is deliberately NOT scoped to live statuses: an already
     * terminated or canceled row still resolves here so the terminate
     * service answers cannot_terminate (422) instead of a 404 for a
     * contract that exists.
     */
    public static function terminatableByExternalId(string $externalId, ?string $organizationId = null): ?self
    {
        /** @var self|null */
        return static::query()
            ->when($organizationId !== null, fn (Builder $q) => $q->where('organization_id', $organizationId))
            ->where('external_id', $externalId)
            ->orderByRaw("status = 'active' DESC, status = 'pending' DESC")
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->first();
    }

    // NOTE: contracts are never soft-deleted in Rails (no Discard) — history
    // keeps terminated and canceled rows.

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Rails: `belongs_to :customer, -> { with_discarded }` — a terminated
     * contract must still resolve its customer for history, serializers and
     * invoices.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Rails: `belongs_to :catalog_plan, -> { with_discarded }, optional: true`. */
    public function catalogPlan(): BelongsTo
    {
        return $this->belongsTo(CatalogPlan::class)->withTrashed();
    }

    /** Rails: `belongs_to :billing_entity, optional: true`. */
    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(BillingEntity::class);
    }

    /** Rails: `belongs_to :payment_method, optional: true`. */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Rails: `has_many :applied_rate_cards, class_name: "ContractRateCard"`. */
    public function appliedRateCards(): HasMany
    {
        return $this->hasMany(ContractRateCard::class);
    }

    // -- Enum helpers -----------------------------------------------------------

    /** Rails: `pending?`. */
    public function pending(): bool
    {
        return (string) $this->getRawOriginal('status') === ContractStatus::Pending->value;
    }

    /** Rails: `active?`. */
    public function active(): bool
    {
        return (string) $this->getRawOriginal('status') === ContractStatus::Active->value;
    }

    /** Rails: `terminated?`. */
    public function terminated(): bool
    {
        return (string) $this->getRawOriginal('status') === ContractStatus::Terminated->value;
    }

    /** Rails: `canceled?`. */
    public function canceled(): bool
    {
        return (string) $this->getRawOriginal('status') === ContractStatus::Canceled->value;
    }

    // -- Scopes -----------------------------------------------------------------

    /** Rails: `scope :live` — pending and active contracts. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    // -- Domain methods (ports of the Rails instance methods) ------------------

    /**
     * Rails: `effective_billing_anchor_date` — the anchor every attached
     * rate card inherits by default: the explicit anchor when one was
     * signed, otherwise the day the contract starts — in the customer's
     * timezone, since the engine interprets dates as customer-local days.
     */
    public function effectiveBillingAnchorDate(): ?CarbonImmutable
    {
        if ($this->billing_anchor_date !== null) {
            return CarbonImmutable::parse((string) $this->billing_anchor_date, 'UTC')->startOfDay();
        }

        if ($this->started_at === null) {
            return null;
        }

        return CarbonImmutable::instance($this->started_at)
            ->setTimezone($this->customer?->applicableTimezone() ?? 'UTC')
            ->startOfDay();
    }

    /**
     * Rails: `editable?` — rate cards are authored while pending only: once
     * the agreement is active (or ended) they are signed.
     */
    public function editable(): bool
    {
        return $this->pending();
    }

    /**
     * Rails: `currency` — the currency fees bill in: the plan's when there
     * is one, otherwise the customer's (a plan-less contract), falling back
     * to the organization default.
     */
    public function currency(): string
    {
        return $this->catalogPlan?->currency
            ?? $this->customer?->currency
            ?? $this->organization->default_currency;
    }

    /** Rails: `applicable_billing_entity`. */
    public function applicableBillingEntity(): ?BillingEntity
    {
        return $this->billingEntity ?? $this->customer?->billingEntity;
    }

    /** Rails: `applicable_billing_entity_id`. */
    public function applicableBillingEntityId(): ?string
    {
        return $this->billing_entity_id ?? $this->customer?->billing_entity_id;
    }

    /**
     * Rails: `default_rate_card_lifecycle` — the billing lifecycle a rate
     * card inherits when attached: it starts on the contract's start day
     * (customer-local) and shares its anchor and clock.
     *
     * @return array{effective_date: CarbonImmutable, billing_anchor_date: ?CarbonImmutable, next_billing_at: mixed}
     */
    public function defaultRateCardLifecycle(?string $billingAnchorDate = null): array
    {
        $startedAt = $this->started_at;

        return [
            'effective_date' => $startedAt === null
                ? CarbonImmutable::now('UTC')->startOfDay()
                : CarbonImmutable::instance($startedAt)
                    ->setTimezone($this->customer->applicableTimezone())
                    ->startOfDay(),
            'billing_anchor_date' => $billingAnchorDate !== null
                ? CarbonImmutable::parse($billingAnchorDate, 'UTC')->startOfDay()
                : $this->effectiveBillingAnchorDate(),
            'next_billing_at' => $startedAt,
        ];
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

        if (($this->external_id ?? '') === '') {
            $errors['external_id'] = ['value_is_mandatory'];
        }

        $this->validateStartedBeforeEnded($errors);

        return $errors;
    }

    /** Rails: `validate_started_before_ended`. @param array<string, list<string>> $errors */
    protected function validateStartedBeforeEnded(array &$errors): void
    {
        if ($this->started_at === null || $this->ended_at === null) {
            return;
        }

        if (CarbonImmutable::instance($this->started_at)->gt(CarbonImmutable::instance($this->ended_at))) {
            $errors['ended_at'] = ['must_be_after_started_at'];
        }
    }

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'billing_time' => \App\Enums\ContractBillingTime::class,
            'payment_method_type' => \App\Enums\ContractPaymentMethodType::class,
            'consolidate_invoice' => 'boolean',
            'billing_anchor_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }
}
