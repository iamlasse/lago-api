<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Charge;
use App\Models\FixedCharge;
use App\Models\ChargeFilter;
use Illuminate\Support\Collection;
use App\Enums\InvoicePaymentStatus;
use App\Models\Invoice as InvoiceModel;

/**
 * Field resolvers for the frozen SDL's `Invoice` type (port of Rails'
 * Types::Invoices::Object computed fields). Plain columns resolve through
 * the snake_case attribute fallback; unported features (credit notes and
 * their offset amounts, payments, activity logs, error details, metadata,
 * invoice custom sections, integration resources, file/xml attachments)
 * keep the null fallback documented in graphql/FULL_SCHEMA_NOTES.md.
 */
class Invoice
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(InvoiceModel $root): ?string
    {
        return $root->statusEnum()?->label();
    }

    /** Rails: the invoice_type enum name — the column stores the integer position. */
    public function invoiceType(InvoiceModel $root): ?string
    {
        return $root->typeEnum()?->label();
    }

    /** Rails: the payment_status enum name — the column stores the integer position. */
    public function paymentStatus(InvoiceModel $root): ?string
    {
        return $root->paymentStatusEnum()?->label();
    }

    /** Rails: the tax_status native-enum name (a plain string column here). */
    public function taxStatus(InvoiceModel $root): ?string
    {
        $raw = $root->getRawOriginal('tax_status');

        return is_string($raw) ? $raw : null;
    }

    /** Rails: payable_type — the literal "Invoice". */
    public function payableType(InvoiceModel $root): string
    {
        return 'Invoice';
    }

    /** Rails: total_due_amount_cents (Invoice#total_due_amount_cents). */
    public function totalDueAmountCents(InvoiceModel $root): int
    {
        return $root->totalDueAmountCents();
    }

    /**
     * Rails: total_settled_amount_cents — total_paid_amount_cents +
     * offset_amount_cents; the credit-note offset precalculation is not
     * ported yet, so the settled amount carries the paid portion only
     * (TODO(port) alongside the CreditNotes slice).
     */
    public function totalSettledAmountCents(InvoiceModel $root): int
    {
        return (int) $root->total_paid_amount_cents;
    }

    /** Rails: payment_dispute_losable? — finalized or voided. */
    public function paymentDisputeLosable(InvoiceModel $root): bool
    {
        return $root->isFinalized() || $root->isVoided();
    }

    /**
     * Rails: voidable? — no lost dispute, nothing paid, no (non-voided)
     * credit notes, then finalized AND payment pending/failed. Credit notes
     * are not ported, so the (always-empty) third guard is skipped
     * (TODO(port) alongside the CreditNotes slice).
     */
    public function voidable(InvoiceModel $root): bool
    {
        if ($root->payment_dispute_lost_at !== null) {
            return false;
        }

        if ((int) $root->total_paid_amount_cents > 0) {
            return false;
        }

        return $root->isFinalized() && in_array($root->paymentStatusEnum(), [InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed], true);
    }

    /**
     * Rails: tax_provider_voidable? — false unless voided with a lost
     * dispute, then error_details.tax_voiding_error.any?. Error details are
     * not ported, so the (always-empty) check resolves false
     * (TODO(port) alongside the ErrorDetails slice).
     */
    public function taxProviderVoidable(InvoiceModel $root): bool
    {
        return false;
    }

    /**
     * Rails: associated_active_wallet_present? — credit? &&
     * customer.wallets.active... The Wallet model is not ported, so no
     * wallet can exist and the check resolves false (TODO(port)).
     */
    public function associatedActiveWalletPresent(InvoiceModel $root): bool
    {
        return false;
    }

    /** Rails: all_charges_have_fees? — every subscription charge has its (base) fee. */
    public function allChargesHaveFees(InvoiceModel $root): bool
    {
        if (! $root->isSubscription()) {
            return true;
        }

        return ! $this->missingCharge($root) && ! $this->missingChargeFilter($root);
    }

    /** Rails: all_fixed_charges_have_fees? — every subscription fixed charge has its fee. */
    public function allFixedChargesHaveFees(InvoiceModel $root): bool
    {
        if (! $root->isSubscription()) {
            return true;
        }

        return ! FixedCharge::query()
            ->join('plans', 'plans.id', '=', 'fixed_charges.plan_id')
            ->join('subscriptions', 'subscriptions.plan_id', '=', 'plans.id')
            ->whereIn('subscriptions.id', $this->subscriptionIds($root))
            ->whereNotIn('fixed_charges.id', $root->fees()->whereNotNull('fees.fixed_charge_id')->select('fees.fixed_charge_id'))
            ->exists();
    }

    /** Rails: applied_taxes — tax_rate DESC (the unpersisted sort in Rails). */
    public function appliedTaxes(InvoiceModel $root): Collection
    {
        if ($root->relationLoaded('appliedTaxes')) {
            return $root->appliedTaxes->sortByDesc('tax_rate')->values();
        }

        return $root->appliedTaxes()->orderByDesc('tax_rate')->get();
    }

    /**
     * Rails: invoice_subscriptions — sorted_invoice_subscriptions, ordered by
     * COALESCE(subscriptions.name, plans.invoice_display_name, plans.name).
     */
    public function invoiceSubscriptions(InvoiceModel $root): Collection
    {
        return $root->invoiceSubscriptions()
            ->join('subscriptions', 'subscriptions.id', '=', 'invoice_subscriptions.subscription_id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->orderByRaw('COALESCE(subscriptions.name, plans.invoice_display_name, plans.name) ASC')
            ->select('invoice_subscriptions.*')
            ->get();
    }

    /** Rails: subscriptions — sorted_subscriptions (through the sorted invoice subscriptions). */
    public function subscriptions(InvoiceModel $root): Collection
    {
        return $this->invoiceSubscriptions($root)->map(fn ($invoiceSubscription) => $invoiceSubscription->subscription);
    }

    /** Rails: regenerated_invoice_id — regenerated_invoice&.id (the invoice regenerated FROM this voided one). */
    public function regeneratedInvoiceId(InvoiceModel $root): ?string
    {
        return InvoiceModel::query()->where('voided_invoice_id', $root->id)->value('id');
    }

    /** Rails: all_charges_have_base_fees? — no subscription charge without a base (filter-less) fee. */
    private function missingCharge(InvoiceModel $root): bool
    {
        return Charge::query()
            ->join('plans', 'plans.id', '=', 'charges.plan_id')
            ->join('subscriptions', 'subscriptions.plan_id', '=', 'plans.id')
            ->whereIn('subscriptions.id', $this->subscriptionIds($root))
            ->whereNotIn(
                'charges.id',
                $root->fees()->charge()->whereNull('fees.charge_filter_id')->select('fees.charge_id'),
            )
            ->exists();
    }

    private function missingChargeFilter(InvoiceModel $root): bool
    {
        return ChargeFilter::query()
            ->join('charges', 'charges.id', '=', 'charge_filters.charge_id')
            ->join('plans', 'plans.id', '=', 'charges.plan_id')
            ->join('subscriptions', 'subscriptions.plan_id', '=', 'plans.id')
            ->whereIn('subscriptions.id', $this->subscriptionIds($root))
            ->whereNotIn(
                'charge_filters.id',
                $root->fees()->charge()->whereNotNull('fees.charge_filter_id')->select('fees.charge_filter_id'),
            )
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\Subscription> */
    private function subscriptionIds(InvoiceModel $root): \Illuminate\Database\Eloquent\Builder
    {
        return $root->subscriptions()->select('subscriptions.id');
    }
}
