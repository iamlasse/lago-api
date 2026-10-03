<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\Fee;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\CreditNoteItem;
use App\Models\FeeAppliedTax;
use App\Models\InvoiceAppliedTax;
use App\Models\CreditNoteAppliedTax as CreditNoteAppliedTaxModel;

/**
 * Port of Rails' CreditNotes::ApplyTaxesService
 * (app/services/credit_notes/apply_taxes_service.rb) — the credit-note tax
 * rollup: one CreditNoteAppliedTax per invoice tax the credited fees'
 * taxes resolve to, with the coupons pro-rated at fee level taken into
 * account.
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    private InvoiceCreditableAmounts $creditableAmounts;

    /** @var array<string, array{invoice_applied_tax: InvoiceAppliedTax, items: list<CreditNoteItem>}>|null */
    private ?array $indexedItems = null;

    public function __construct(
        private readonly Invoice $invoice,
        /** @var iterable<CreditNoteItem> */
        private readonly iterable $items,
    ) {
        $this->creditableAmounts = new InvoiceCreditableAmounts($invoice);
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of(
            'applied_taxes',
            'coupons_adjustment_amount_cents',
            'precise_taxes_amount_cents',
            'taxes_amount_cents',
            'taxes_rate',
            'precise_tax_amounts',
        );

        $result->applied_taxes = [];
        $result->precise_tax_amounts = [];
        $result->coupons_adjustment_amount_cents = $this->couponsAdjustmentAmountCents();

        $preciseTaxesAmountCents = '0';
        $taxesRate = 0.0;

        $indexedItems = $this->indexItemsByInvoiceTax($result);
        if ($indexedItems === null) {
            return $result;
        }
        $this->indexedItems = $indexedItems;

        foreach ($indexedItems as $taxKey => $entry) {
            $invoiceAppliedTax = $entry['invoice_applied_tax'];

            $preciseBaseAmountCents = MoneyMath::mul($this->baseAmounts()[$taxKey], $this->taxesBaseRate($invoiceAppliedTax));

            $preciseTaxAmountCents = $this->creditableAmounts->appliedTaxIsProviderTax($invoiceAppliedTax)
                ? $this->bookedTaxToCredit($taxKey, array_values(array_unique($entry['items'], SORT_REGULAR)))
                : MoneyMath::fdiv(MoneyMath::mul($preciseBaseAmountCents, (string) $invoiceAppliedTax->tax_rate), '100');

            $appliedTax = $this->buildAppliedTax($invoiceAppliedTax, $preciseBaseAmountCents, $preciseTaxAmountCents);

            $result->applied_taxes[] = $appliedTax;
            $result->precise_tax_amounts[] = $preciseTaxAmountCents;

            $preciseTaxesAmountCents = MoneyMath::add($preciseTaxesAmountCents, $preciseTaxAmountCents);
            $taxesRate += $this->proRatedTaxesRate($appliedTax, $result);
        }

        $result->precise_taxes_amount_cents = $preciseTaxesAmountCents;
        $result->taxes_amount_cents = array_sum(array_map(
            fn (InvoiceAppliedTax $tax): int => (int) $tax->amount_cents,
            $result->applied_taxes,
        ));
        $result->taxes_rate = MoneyMath::roundTo((string) $taxesRate, 5);

        return $result;
    }

    /**
     * Rails: CreditNote::AppliedTax.new(...) — unsaved; the caller pushes it
     * onto the credit note.
     */
    private function buildAppliedTax(
        InvoiceAppliedTax $invoiceAppliedTax,
        string $preciseBaseAmountCents,
        string $preciseTaxAmountCents,
    ): CreditNoteAppliedTaxModel {
        return new CreditNoteAppliedTaxModel([
            'organization_id' => $this->invoice->organization_id,
            'tax_id' => $invoiceAppliedTax->tax_id,
            'tax_description' => $invoiceAppliedTax->tax_description,
            'tax_code' => $invoiceAppliedTax->tax_code,
            'tax_name' => $invoiceAppliedTax->tax_name,
            'tax_rate' => $invoiceAppliedTax->tax_rate,
            'amount_currency' => $this->invoice->currency,
            'base_amount_cents' => MoneyMath::round($preciseBaseAmountCents),
            'amount_cents' => MoneyMath::round($preciseTaxAmountCents),
        ]);
    }

    /**
     * Rails: booked_tax_to_credit — the booked provider tax credited
     * pro-rata to each item's share of its fee.
     *
     * @param  list<CreditNoteItem>  $items
     */
    private function bookedTaxToCredit(string $taxKey, array $items): string
    {
        $bookedTax = $this->bookedTaxByKeyAndFee()[$taxKey];

        $total = '0';
        foreach ($items as $item) {
            $total = MoneyMath::add($total, $this->creditedPortion($bookedTax[$item->fee_id], $item));
        }

        return $total;
    }

    /** Rails: credited_portion(fee_amount_cents, item). */
    private function creditedPortion(int $feeAmountCents, CreditNoteItem $item): string
    {
        if ((int) $item->fee->amount_cents === 0) {
            return '0';
        }

        return MoneyMath::fdiv(
            MoneyMath::mul((string) $feeAmountCents, (string) $item->precise_amount_cents),
            (string) $item->fee->amount_cents,
        );
    }

    /**
     * Rails: booked_tax_by_key_and_fee — the invoice's booked per-fee-tax
     * split, regrouped by the invoice tax each fee tax resolves to.
     *
     * @return array<string, array<string, int>> "[code,rate]" => fee id => cents
     */
    private function bookedTaxByKeyAndFee(): array
    {
        ['units' => $units, 'booked' => $booked] = $this->creditableAmounts->bookedTaxByFeeTaxWithUnits();

        $result = [];
        foreach ($booked as $unitKey => $amountCents) {
            $unit = $units[$unitKey] ?? null;
            if (! $unit instanceof FeeAppliedTax) {
                continue;
            }

            $invoiceAppliedTax = $this->creditableAmounts->appliedTaxFor($unit);
            if ($invoiceAppliedTax === null) {
                continue;
            }

            $byFee = $result[$this->taxKey($invoiceAppliedTax)] ?? [];
            $byFee[$unit->fee_id] = ($byFee[$unit->fee_id] ?? 0) + $amountCents;
            $result[$this->taxKey($invoiceAppliedTax)] = $byFee;
        }

        return $result;
    }

    /**
     * NOTE: indexes the credit note items by the invoice applied tax their
     * fee taxes resolve to, keyed by that invoice tax's code and rate.
     * Keying on the resolved invoice tax, not on the fee tax, lets two fee
     * taxes that resolve to the same invoice tax share a single credit note
     * tax instead of colliding on the (credit_note_id, tax_code, tax_rate)
     * index. An item is listed once per fee tax, not once per key: a fee can
     * carry several provider components with the same code and rate, and
     * each one must be credited, as it was taxed.
     *
     * Returns null, with the failure recorded on the result, when a fee tax
     * cannot be resolved.
     *
     * @return array<string, array{invoice_applied_tax: InvoiceAppliedTax, items: list<CreditNoteItem>}>|null
     */
    private function indexItemsByInvoiceTax(BaseResult $result): ?array
    {
        $index = [];

        foreach ($this->items as $item) {
            foreach ($item->fee->appliedTaxes as $feeAppliedTax) {
                $invoiceAppliedTax = $this->creditableAmounts->appliedTaxFor($feeAppliedTax);
                if ($invoiceAppliedTax === null) {
                    $result->serviceFailure(
                        'invoice_applied_tax_not_found',
                        sprintf(
                            'Invoice %s has no applied tax matching %s',
                            $this->invoice->id,
                            implode(', ', [$feeAppliedTax->tax_code, $feeAppliedTax->tax_rate]),
                        ),
                    );

                    return null;
                }

                $key = $this->taxKey($invoiceAppliedTax);
                $index[$key] ??= ['invoice_applied_tax' => $invoiceAppliedTax, 'items' => []];
                $index[$key]['items'][] = $item;
            }
        }

        return $index;
    }

    private function itemsAmountCents(): string
    {
        $total = '0';
        foreach ($this->items as $item) {
            $total = MoneyMath::add($total, (string) $item->precise_amount_cents);
        }

        return $total;
    }

    /** Rails: coupons_adjustment_amount_cents — pro-rated per item, 0 before version 3. */
    private function couponsAdjustmentAmountCents(): string
    {
        if ((int) $this->invoice->version_number < InvoiceCreditableAmounts::COUPON_BEFORE_VAT_VERSION) {
            return '0';
        }

        $total = '0';
        foreach ($this->items as $item) {
            $total = MoneyMath::add($total, $this->proratedCouponAmountCents($item));
        }

        return $total;
    }

    private function proratedCouponAmountCents(CreditNoteItem $item): string
    {
        $itemFeeRate = (int) $item->fee->amount_cents === 0
            ? '0'
            : MoneyMath::fdiv((string) $item->precise_amount_cents, (string) $item->fee->amount_cents);

        return MoneyMath::mul((string) $item->fee->precise_coupons_amount_cents, $itemFeeRate);
    }

    /**
     * @return array<string, string> tax key => precise base amount
     */
    private function baseAmounts(): array
    {
        $amounts = [];
        foreach ($this->indexedItems as $taxKey => $entry) {
            $total = '0';
            foreach ($entry['items'] as $item) {
                $total = MoneyMath::add($total, MoneyMath::sub(
                    (string) $item->precise_amount_cents,
                    $this->proratedCouponAmountCents($item),
                ));
            }
            $amounts[$taxKey] = $total;
        }

        return $amounts;
    }

    /**
     * NOTE: a tax might not be applied to all items of the credit note. In
     * order to compute the taxes_rate, we apply a pro-rata of the items
     * attached to the tax on the total items amount.
     */
    private function proRatedTaxesRate(CreditNoteAppliedTaxModel $appliedTax, BaseResult $result): float
    {
        $taxItemsAmountCents = $this->baseAmounts()[$this->taxKey($appliedTax)];
        $totalItemsAmountCents = MoneyMath::sub($this->itemsAmountCents(), (string) $result->coupons_adjustment_amount_cents);

        $itemsRate = MoneyMath::compare($totalItemsAmountCents, '0') === 0
            ? 0.0
            : (float) MoneyMath::fdiv($taxItemsAmountCents, $totalItemsAmountCents);

        return $itemsRate * (float) $appliedTax->tax_rate;
    }

    /**
     * Rails: taxes_base_rate(applied_tax) — the share of the tax's fees
     * amount that was actually taxable.
     */
    private function taxesBaseRate(InvoiceAppliedTax $appliedTax): string
    {
        $feesAmountCents = (int) $appliedTax->fees_amount_cents;

        if ($feesAmountCents === 0) {
            return '1';
        }

        return MoneyMath::fdiv(
            (string) $this->creditableAmounts->appliedTaxTaxableAmountCents($appliedTax),
            (string) $feesAmountCents,
        );
    }

    private function taxKey(InvoiceAppliedTax|CreditNoteAppliedTaxModel $appliedTax): string
    {
        return $appliedTax->tax_code.'|'.$appliedTax->tax_rate;
    }
}
