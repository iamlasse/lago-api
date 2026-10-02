<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\SubscriptionInvoicingReason;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' InvoiceSubscription (app/models/invoice_subscription.rb).
 *
 * The `matching?` guard is the double-billing protection for the recurring
 * billing process — it must behave exactly like Rails.
 */
#[Fillable([
    'invoice_id',
    'subscription_id',
    'recurring',
    'timestamp',
    'from_datetime',
    'to_datetime',
    'charges_from_datetime',
    'charges_to_datetime',
    'invoicing_reason',
    'organization_id',
    'regenerated_invoice_id',
    'fixed_charges_from_datetime',
    'fixed_charges_to_datetime',
])]
#[Table(name: 'invoice_subscriptions')]
class InvoiceSubscription extends BaseModel
{
    use HasFactory;

    /**
     * Port of `InvoiceSubscription.matching?(subscription, boundaries, recurring: true)`:
     * a previous invoice subscription already covers these boundaries —
     * used to prevent double billing on billing day.
     */
    public static function matching(Subscription $subscription, BillingPeriodBoundaries $boundaries, bool $recurring = true): bool
    {
        // Bind with the model's timestamp(6) format: Rails' matching? compares
        // the full-precision boundaries (end-of-period carries .999999), and
        // the default grammar format would truncate the fraction and miss.
        $format = (new static)->getDateFormat();

        $bind = function (mixed $value) use ($format) {
            return $value instanceof DateTimeInterface ? $value->format($format) : $value;
        };

        $baseQuery = static::query()
            ->where('subscription_id', $subscription->id)
            ->where('from_datetime', $bind($boundaries->fromDatetime))
            ->where('to_datetime', $bind($boundaries->toDatetime));

        if ($recurring) {
            $baseQuery->recurring();
        }

        $plan = $subscription->plan;

        if ($plan?->chargesBilledInMonthlySplitIntervals()) {
            $baseQuery
                ->where('charges_from_datetime', $boundaries->chargesFromDatetime)
                ->where('charges_to_datetime', $boundaries->chargesToDatetime);
        }

        if ($plan?->fixedChargesBilledInMonthlySplitIntervals()) {
            $baseQuery
                ->where('fixed_charges_from_datetime', $boundaries->fixedChargesFromDatetime)
                ->where('fixed_charges_to_datetime', $boundaries->fixedChargesToDatetime);
        }

        return $baseQuery->exists();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Port of `fees` — the subscription's fees attached to this invoice.
     */
    public function fees()
    {
        return Fee::query()
            ->where('subscription_id', $this->subscription_id)
            ->where('invoice_id', $this->invoice_id);
    }

    public function subscriptionFee(): ?Fee
    {
        return $this->fees()->subscription()->first();
    }

    /**
     * Port of `previous_invoice_subscription` — the latest earlier invoice
     * subscription of this subscription that carries a subscription fee.
     */
    public function previousInvoiceSubscription(): ?self
    {
        return static::query()
            ->where('subscription_id', $this->subscription_id)
            ->where('from_datetime', '<=', $this->from_datetime)
            ->where('id', '!=', $this->id)
            ->latest('from_datetime')
            ->get()
            ->first(fn (self $invoiceSubscription) => $invoiceSubscription->subscriptionFee() !== null);
    }

    /** Rails `invoicing_reason` enum name ("subscription_periodic", ...). */
    public function invoicingReasonName(): ?string
    {
        if ($this->invoicing_reason === null) {
            return null;
        }

        return SubscriptionInvoicingReason::tryFrom((string) $this->invoicing_reason)?->value
            ?? (string) $this->invoicing_reason;
    }

    public function subscriptionStarting(): bool
    {
        return $this->invoicingReasonName() === 'subscription_starting';
    }

    /** Port of `scope :recurring`. */
    #[Scope]
    protected function recurring(Builder $query): Builder
    {
        return $query->where('recurring', true);
    }

    protected function casts(): array
    {
        return [
            'recurring' => 'boolean',
            'timestamp' => 'datetime',
            'from_datetime' => 'datetime',
            'to_datetime' => 'datetime',
            'charges_from_datetime' => 'datetime',
            'charges_to_datetime' => 'datetime',
            'fixed_charges_from_datetime' => 'datetime',
            'fixed_charges_to_datetime' => 'datetime',
        ];
    }
}
