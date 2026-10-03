<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\Fee;
use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Models\CreditNote;
use App\Support\MoneyMath;
use App\Support\Allocation;
use App\Models\FeeAppliedTax;
use App\Models\CreditNoteItem;
use App\Models\InvoiceAppliedTax;
use App\Enums\InvoicePaymentStatus;

/**
 * The invoice-side amounts the credit-note services need
 * (app/models/invoice.rb): fee_total/remaining/available-to-credit/
 * creditable/refundable amounts, and the booked-tax split the tax
 * computations read back.
 *
 * They live in a helper rather than on the Invoice model because the
 * frozen-schema port keeps Invoice billing-pipeline scoped; when the invoice
 * credit-note offsets precalculation is ported onto the model these can move
 * (TODO(port)).
 *
 * NOTE: wallets are not ported yet — every wallet-derived amount
 * (creditable_from_wallet_amount_cents, associated_active_wallet) resolves
 * as if no wallet existed (TODO(port) with the Wallets slice). Provider
 * taxes (Stripe Tax) are not ported either; the booked-tax machinery below
 * already carries the general path they plug into (TODO(port) with the
 * ProviderTaxes slice).
 */
class InvoiceCreditableAmounts
{
    /** Rails: Invoice::CREDIT_NOTES_MIN_VERSION. */
    public const CREDIT_NOTES_MIN_VERSION = 2;

    /** Rails: Invoice::COUPON_BEFORE_VAT_VERSION. */
    public const COUPON_BEFORE_VAT_VERSION = 3;

    public function __construct(private readonly Invoice $invoice) {}

    /** Rails: Invoice#fee_total_amount_cents. */
    public function feeTotalAmountCents(): int
    {
        return (int) $this->invoice->fees_amount_cents + (int) $this->invoice->taxes_amount_cents;
    }

    /** Rails: Invoice#provider_taxes?. */
    public function providerTaxes(): bool
    {
        return $this->invoice->appliedTaxes->contains(
            fn (InvoiceAppliedTax $appliedTax): bool => $this->appliedTaxIsProviderTax($appliedTax),
        );
    }

    /**
     * Rails: Invoice#booked_tax_by_fee — { fee => booked tax cents }.
     * Native fee taxes round one by one while the invoice rounds once, and
     * provider invoices booked before fee amounts were stored drift the same
     * way: split what the invoice charged, first across its taxes, then
     * within each tax across its fee rows, so a full credit matches both.
     *
     * @return array<string, int> fee id => cents
     */
    public function bookedTaxByFee(): array
    {
        $booked = $this->bookedTaxByFeeTax();

        $result = [];
        foreach ($this->orderedFees() as $fee) {
            $total = 0;
            foreach ($this->bookedTaxUnits($fee) as $unitKey => $unit) {
                $total += $booked[$unitKey];
            }
            $result[$fee->id] = $total;
        }

        return $result;
    }

    /**
     * Rails: Invoice#booked_tax_by_fee_tax — { unit key => booked cents }.
     * Fees without rows and rows matching no invoice tax share one group,
     * weighted by their own cents, so the invoice tax is still spent in full.
     *
     * @return array<string, int> unit key ("fee:<id>" / "tax:<id>") => cents
     */
    public function bookedTaxByFeeTax(): array
    {
        return $this->bookedTaxByFeeTaxWithUnits()['booked'];
    }

    /**
     * The booked per-fee-tax split together with the unit map it is keyed
     * by (Rails keys the Ruby hash by the model objects themselves).
     *
     * @return array{units: array<string, Fee|FeeAppliedTax>, booked: array<string, int>}
     */
    public function bookedTaxByFeeTaxWithUnits(): array
    {
        // Rails: ordered_fees_for_booked_tax.flat_map { booked_tax_units(fee) }
        $units = [];
        foreach ($this->orderedFees() as $fee) {
            foreach ($this->bookedTaxUnits($fee) as $unitKey => $unit) {
                $units[$unitKey] = $unit;
            }
        }

        // Rails: booked_tax_units_by_invoice_tax — group the units by the
        // invoice tax their fee tax resolves to (fees without rows group
        // under nil), groups sorted by [0, code, rate, index] / [1, index]
        // for deterministic largest-remainder allocation.
        $unitKeysByTax = [];
        foreach ($units as $unitKey => $unit) {
            $tax = $unit instanceof FeeAppliedTax ? $this->appliedTaxFor($unit) : null;
            $unitKeysByTax[$tax?->getKey() ?? 'nil'][] = $unitKey;
        }

        $groupTaxes = [];
        foreach ($unitKeysByTax as $taxId => $unitKeys) {
            $groupTaxes[$taxId] = $taxId === 'nil'
                ? null
                : $this->invoice->appliedTaxes->firstWhere('id', $taxId);
        }

        uksort($groupTaxes, function (string $a, string $b) use ($groupTaxes): int {
            $taxA = $groupTaxes[$a];
            $taxB = $groupTaxes[$b];

            if ($taxA === null || $taxB === null) {
                // The nil group sorts last ([1, index] vs [0, ...]).
                return $taxA === $taxB ? 0 : ($taxA === null ? 1 : -1);
            }

            return [(string) $taxA->tax_code, (float) $taxA->tax_rate] <=> [(string) $taxB->tax_code, (float) $taxB->tax_rate];
        });

        // Rails: booked_tax_by_invoice_tax — per-group amounts, reallocated
        // through the largest-remainder split when they don't add up to the
        // invoice's booked taxes.
        $groupOrder = array_keys($groupTaxes);
        $groupBooked = [];
        $groupExact = [];
        foreach ($groupOrder as $taxId) {
            $booked = 0;
            $exact = '0';
            foreach ($unitKeysByTax[$taxId] as $unitKey) {
                $booked += $this->bookedTaxCents($units[$unitKey]);
                $exact = MoneyMath::add($exact, $this->exactTaxCents($units[$unitKey]));
            }
            $groupBooked[$taxId] = $booked;
            $groupExact[$taxId] = $exact;
        }

        $bookedSum = array_sum($groupBooked);
        $taxAmounts = $bookedSum === (int) $this->invoice->taxes_amount_cents
            ? array_values($groupBooked)
            : Allocation::call(
                (int) $this->invoice->taxes_amount_cents,
                array_map(
                    fn (string $taxId): int|string => $this->bookedTaxWeights($groupExact[$taxId], $groupBooked[$taxId]),
                    $groupOrder,
                ),
            );

        // Rails: units_by_tax.values.zip(tax_amounts) → split_booked_tax per
        // group, merged into the booked map.
        $booked = [];
        foreach ($groupOrder as $groupIndex => $taxId) {
            $unitKeys = $unitKeysByTax[$taxId];
            $amountCents = $taxAmounts[$groupIndex];

            $sumGroupBooked = $groupBooked[$taxId];
            if ($sumGroupBooked === $amountCents) {
                $split = array_combine($unitKeys, array_map(fn (string $k): int => $this->bookedTaxCents($units[$k]), $unitKeys));
            } else {
                $allocated = Allocation::call(
                    $amountCents,
                    array_map(
                        fn (string $unitKey): int|string => $this->bookedTaxWeights(
                            $this->exactTaxCents($units[$unitKey]),
                            $this->bookedTaxCents($units[$unitKey]),
                        ),
                        $unitKeys,
                    ),
                );
                $split = array_combine($unitKeys, $allocated);
            }

            foreach ($unitKeys as $unitKey) {
                $booked[$unitKey] = $split[$unitKey];
            }
        }

        return ['units' => $units, 'booked' => $booked];
    }

    /**
     * Rails: Invoice#applied_tax_for(fee_applied_tax) — the invoice tax with
     * the same code and rate; when no invoice tax has that rate (the fee and
     * the invoice were taxed at different rates), the invoice tax carrying
     * the same code, but only if exactly one does.
     */
    public function appliedTaxFor(FeeAppliedTax $feeAppliedTax): ?InvoiceAppliedTax
    {
        $invoiceTaxes = $this->invoice->appliedTaxes;

        $exactMatch = $invoiceTaxes->first(
            fn (InvoiceAppliedTax $tax): bool => $tax->tax_code === $feeAppliedTax->tax_code
                && (float) $tax->tax_rate === (float) $feeAppliedTax->tax_rate,
        );
        if ($exactMatch !== null) {
            return $exactMatch;
        }

        $codeMatches = $invoiceTaxes->filter(
            fn (InvoiceAppliedTax $tax): bool => $tax->tax_code === $feeAppliedTax->tax_code,
        );

        return $codeMatches->count() === 1 ? $codeMatches->first() : null;
    }

    /** Rails: Invoice#available_to_credit_amount_cents. */
    public function availableToCreditAmountCents(): int
    {
        if ((int) $this->invoice->version_number < self::CREDIT_NOTES_MIN_VERSION || $this->invoice->isDraft()) {
            return 0;
        }

        return min($this->feesAvailableToCreditAmountCents(), $this->remainingInvoiceAmountCents());
    }

    /** Rails: Invoice#creditable_amount_cents — credit invoices are credited as refund only. */
    public function creditableAmountCents(): int
    {
        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            return 0;
        }

        return $this->availableToCreditAmountCents();
    }

    /** Rails: Invoice#offsettable_amount_cents. */
    public function offsettableAmountCents(): int
    {
        $dueAmountCents = $this->totalDueAmountCents();

        // When the invoice type is credit there is no partial payment/
        // refund/offset — only the full amount.
        if ($this->invoice->typeEnum() === InvoiceType::Credit
            && $dueAmountCents > 0
            && ($this->invoice->paymentPending() || $this->invoice->paymentStatusEnum() === InvoicePaymentStatus::Failed)) {
            return (int) $this->invoice->total_amount_cents;
        }

        return min($dueAmountCents, $this->creditableAmountCents());
    }

    /** Rails: Invoice#refundable_amount_cents. */
    public function refundableAmountCents(): int
    {
        if ((int) $this->invoice->version_number < self::CREDIT_NOTES_MIN_VERSION || $this->invoice->isDraft()) {
            return 0;
        }

        if (! $this->invoice->paymentSucceeded()
            && (int) $this->invoice->total_paid_amount_cents === (int) $this->invoice->total_amount_cents) {
            return 0;
        }

        $alreadyRefundedCents = (int) CreditNote::query()->where('invoice_id', $this->invoice->id)->sum('refund_amount_cents');
        $remainingPaidCents = (int) $this->invoice->total_paid_amount_cents - $alreadyRefundedCents;

        // When the invoice is for prepaid credits we can issue a credit note
        // only as refund, so creditable_amount_cents is always 0 — but on
        // that case we should allow a refund only if the wallet balance is
        // greater or equal than the remaining paid amount. Wallets are not
        // ported yet, so the wallet bound resolves to 0 (TODO(port)).
        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            return 0;
        }

        $refundableCents = min($remainingPaidCents, $this->creditableAmountCents());

        return max($refundableCents, 0);
    }

    /** Rails: Invoice#remaining_invoice_amount_cents. */
    public function remainingInvoiceAmountCents(): int
    {
        $creditedTotal = (int) CreditNote::query()->where('invoice_id', $this->invoice->id)->sum('total_amount_cents');

        return max((int) $this->invoice->sub_total_including_taxes_amount_cents - $creditedTotal, 0);
    }

    /** Rails: Invoice#offset_amount_cents — finalized credit notes' offsets. */
    public function offsetAmountCents(): int
    {
        return (int) CreditNote::query()
            ->where('invoice_id', $this->invoice->id)
            ->finalized()
            ->sum('offset_amount_cents');
    }

    /** Rails: Invoice#total_due_amount_cents (the model method, read-only invoice). */
    public function totalDueAmountCents(): int
    {
        if ($this->invoice->isVoided()) {
            return 0;
        }

        return (int) $this->invoice->total_amount_cents
            - (int) $this->invoice->total_paid_amount_cents
            - $this->offsetAmountCents();
    }

    /** Rails: Invoice#fees_available_to_credit_amount_cents. */
    public function feesAvailableToCreditAmountCents(): int
    {
        $bookedTax = $this->bookedTaxByFee();

        $total = '0';
        foreach ($this->orderedFees() as $fee) {
            $share = $this->creditableShare($fee);
            $feeBase = MoneyMath::add($fee->subTotalExcludingTaxesAmountCents(), (string) ($bookedTax[$fee->id] ?? 0));
            $total = MoneyMath::add($total, MoneyMath::mul($share, $feeBase));
        }

        return MoneyMath::round($total);
    }

    /** Rails: Fee#creditable_amount_cents. */
    public function feeCreditableAmountCents(Fee $fee): int
    {
        $remainingAmount = (int) $fee->amount_cents
            - (int) CreditNoteItem::query()->where('fee_id', $fee->id)->sum('amount_cents');

        // NOTE: credit (prepaid) invoices clamp the fee's creditable amount
        // to the wallet balance; wallets are not ported yet, so the bound is
        // 0 for those invoices (TODO(port)).
        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            return min($remainingAmount, 0);
        }

        return $remainingAmount;
    }

    /** Rails: Invoice#creditable_share(fee) (private). */
    public function creditableShare(Fee $fee): string
    {
        if ((int) $fee->amount_cents === 0) {
            return '0';
        }

        return MoneyMath::fdiv((string) $this->feeCreditableAmountCents($fee), (string) $fee->amount_cents);
    }

    // -- Applied-tax helpers ----------------------------------------------------

    /** Rails: Invoice::AppliedTax#provider_tax?. */
    public function appliedTaxIsProviderTax(InvoiceAppliedTax $appliedTax): bool
    {
        return $appliedTax->tax_id === null && (int) $appliedTax->taxable_base_amount_cents > 0;
    }

    /** Rails: Invoice::AppliedTax#taxable_amount_cents. */
    public function appliedTaxTaxableAmountCents(InvoiceAppliedTax $appliedTax): int
    {
        $baseAmount = (int) $appliedTax->taxable_base_amount_cents;

        if ($baseAmount === 0) {
            return (int) $appliedTax->fees_amount_cents;
        }

        return $baseAmount;
    }

    // -- Booked-tax internals (Invoice private methods) ------------------------

    /** Rails: ordered_fees_for_booked_tax — created_at, id, original order. */
    private function orderedFees(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->invoice->fees
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Rails: booked_tax_units(fee) — the fee's fee-tax rows (ordered by id)
     * or the fee itself when it has no rows.
     *
     * @return array<string, Fee|FeeAppliedTax> unit key => unit
     */
    private function bookedTaxUnits(Fee $fee): array
    {
        $rows = $fee->appliedTaxes->sortBy(fn (FeeAppliedTax $row): string => (string) $row->id)->values();

        if ($rows->isEmpty()) {
            return ['fee:'.$fee->id => $fee];
        }

        $units = [];
        foreach ($rows as $row) {
            $units['tax:'.$row->id] = $row;
        }

        return $units;
    }

    /** Rails: Invoice#booked_tax_cents(unit). */
    private function bookedTaxCents(Fee|FeeAppliedTax $unit): int
    {
        return $unit instanceof Fee ? (int) $unit->taxes_amount_cents : (int) $unit->amount_cents;
    }

    /** Rails: Invoice#exact_tax_cents(unit) — the stored precise amounts. */
    private function exactTaxCents(Fee|FeeAppliedTax $unit): string
    {
        return $unit instanceof Fee ? (string) $unit->taxes_precise_amount_cents : (string) $unit->precise_amount_cents;
    }

    /**
     * Rails: Invoice#booked_tax_weights(exact, booked) — taxes billed before
     * exact amounts were stored carry none, so their rounded cents weight
     * the split; when those are zero too, the cents still land on the first
     * rows.
     */
    private function bookedTaxWeights(string $exact, int $booked): int|string
    {
        if (MoneyMath::compare($exact, '0') !== 0) {
            return $exact;
        }

        if ($booked !== 0) {
            return $booked;
        }

        return 1;
    }
}
