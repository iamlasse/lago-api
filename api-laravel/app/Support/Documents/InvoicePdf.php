<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Models\Customer;
use App\Support\Currency;
use App\Enums\InvoiceType;
use Carbon\CarbonInterface;
use App\Models\BillingEntity;
use App\Models\InvoiceAppliedTax;
use Illuminate\Support\Facades\App;

/**
 * View-context for the invoice document templates (the Blade port of Rails'
 * Slim invoice templates under app/views/templates/invoices/).
 *
 * The methods here are the PHP port of the Rails view helpers the templates
 * lean on — MoneyHelper, TaxHelper, FeeDisplayHelper (fee_title only),
 * I18n.l(:default) and the invoice/subscription/fee `invoice_name` model
 * methods the Slim context (the invoice itself) answered.
 */
final class InvoicePdf
{
    /** Rails MoneyHelper::SYMBOLS_CURRENCIES — currencies shown with a symbol. */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    public function __construct(private readonly Invoice $invoice) {}

    /** Rails: GeneratePdfService#template. */
    public static function templateName(Invoice $invoice): string
    {
        // Rails selects invoices/v{version}/one_off, /charge, /fixed_charge
        // and self_billed templates — near-copies of the v4 base with small
        // headline differences, and keeps legacy v1–v3 layouts.
        // TODO(port): those variants; every invoice renders the v4 layout in
        // the meantime.
        $version = max(4, (int) $invoice->version_number);

        return 'documents.invoices.v'.$version;
    }

    /** Render a document template under the customer's document locale. */
    public static function render(string $template, Invoice $invoice): string
    {
        $previous = App::getLocale();

        // Rails: I18n.with_locale(invoice.customer.preferred_document_locale).
        $locale = $invoice->customer?->preferredDocumentLocale();

        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            return view($template, [
                'invoice' => $invoice,
                'ctx' => new self($invoice),
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /** Shared view data for the documents footer (page numbering + number). */
    public static function sharedViewData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'number' => (string) $invoice->number,
            'ctx' => new self($invoice),
        ];
    }

    // -- Template convenience accessors (Slim called these on the invoice) --

    public function invoice(): Invoice
    {
        return $this->invoice;
    }

    public function customer(): ?Customer
    {
        return $this->invoice->customer;
    }

    public function billingEntity(): ?BillingEntity
    {
        return $this->invoice->billingEntity;
    }

    // -- Money (port of MoneyHelper) -----------------------------------------

    /**
     * Port of MoneyHelper.format — "%u%n" (symbol-prefixed) for the symbol
     * currencies, "%{iso_code} %n" otherwise, with the en money separators
     * (decimal_mark ".", thousands_separator ",").
     */
    public function money(null|int|float|string $amountCents, ?string $currency = null): string
    {
        $currency = $currency ?? $this->invoice->currency;
        $amount = (float) ($amountCents ?? 0) / Currency::subunitToUnit($currency);
        $formatted = number_format($amount, Currency::exponent($currency), '.', ',');

        $symbol = self::SYMBOLS[mb_strtoupper($currency ?? '')] ?? null;

        return $symbol === null
            ? sprintf('%s %s', $currency, $formatted)
            : sprintf('%s%s', $symbol, $formatted);
    }

    /**
     * Port of MoneyHelper.format_with_precision — a precise decimal amount
     * (fee.precise_unit_amount) formatted like money().
     */
    public function moneyWithPrecision(null|string|int|float $amount, ?string $currency = null): string
    {
        return $this->money((float) ($amount ?? 0) * Currency::subunitToUnit($currency ?? $this->invoice->currency), $currency);
    }

    // -- Dates (I18n.l format: :default — "%b %d, %Y") ------------------------

    public function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value : \Illuminate\Support\Carbon::parse($value);

        return $date->translatedFormat('M d, Y');
    }

    // -- Names (invoice_name family) ------------------------------------------

    /** Port of Plan#invoice_name. */
    public function planInvoiceName(mixed $plan): string
    {
        $displayName = $plan->invoice_display_name ?? null;

        return filled($displayName) ? (string) $displayName : (string) $plan->name;
    }

    /** Port of Subscription#invoice_name. */
    public function subscriptionInvoiceName(mixed $subscription): string
    {
        $name = $subscription->name ?? null;

        return filled($name) ? (string) $name : $this->planInvoiceName($subscription->plan);
    }

    /**
     * Port of `I18n.t("invoice.#{subscription.plan.interval}")` — maps the
     * stored PlanInterval integer to the locale's interval word.
     */
    public function planIntervalLabel(mixed $plan): string
    {
        $label = match ((int) ($plan->interval ?? -1)) {
            0 => __('invoice.week'),
            1 => __('invoice.month'),
            2 => __('invoice.year'),
            3 => __('invoice.quarter'),
            4 => __('invoice.half_year'),
            default => '',
        };

        return (string) $label;
    }

    /** Port of Fee#invoice_name. */
    public function feeInvoiceName(Fee $fee): string
    {
        if (filled($fee->invoice_display_name)) {
            return (string) $fee->invoice_display_name;
        }

        return match ($fee->typeEnum()) {
            FeeType::Charge => filled($fee->charge?->invoice_display_name)
                ? (string) $fee->charge->invoice_display_name
                : (string) $fee->charge?->billableMetric?->name,
            FeeType::AddOn => (string) $fee->addOn?->name,
            FeeType::FixedCharge => filled($fee->fixedCharge?->invoice_display_name)
                ? (string) $fee->fixedCharge->invoice_display_name
                // TODO(port): fixed_charge_add_on.invoice_name (add-ons on
                // fixed charges — the frozen schema has no such relation yet).
                : (string) ($fee->fixedCharge?->name ?? $fee->fixedCharge?->invoice_display_name),
            default => $this->subscriptionInvoiceName($fee->subscription),
        };
    }

    /** Port of FeeDisplayHelper.fee_title (invoice_name + grouped_by + filter). */
    public function feeTitle(Fee $fee): string
    {
        $title = $this->feeInvoiceName($fee);

        $groupedBy = $this->groupedByDisplay($fee);

        if ($groupedBy !== '') {
            $title .= $groupedBy;
        }

        // TODO(port): fee.filtered? / filter_display_name — charge filters'
        // invoice display names are not computed in this port yet.

        return $title;
    }

    /** Port of Fee#grouped_by_display. */
    public function groupedByDisplay(Fee $fee): string
    {
        if ($fee->typeEnum() !== FeeType::Charge) {
            return '';
        }

        $values = array_values(array_filter((array) ($fee->grouped_by ?? []), fn ($v) => $v !== null && $v !== ''));

        if ($values === []) {
            return '';
        }

        return ' • '.implode(' • ', array_map(strval(...), $values));
    }

    // -- Taxes (port of TaxHelper) ---------------------------------------------

    /**
     * Port of TaxHelper.applied_taxes — the fee's applied tax rates, one div
     * per rate, descending; "0.0%" when the fee carries no taxes.
     *
     * @return list<string>
     */
    public function taxRates(?Fee $fee): array
    {
        $rates = $fee === null
            ? []
            : $fee->appliedTaxes()->orderByDesc('tax_rate')->pluck('tax_rate')->all();

        if ($rates === []) {
            return ['0.0%'];
        }

        return array_map(fn ($rate) => $rate.'%', $rates);
    }

    // -- Invoice headline / totals ----------------------------------------------

    /** Port of Invoice#document_invoice_name. */
    public function documentInvoiceName(): string
    {
        $invoice = $this->invoice;

        if ((bool) $invoice->self_billed) {
            return __('invoice.self_billed.document_name');
        }

        if ($invoice->isCredit()) {
            return __('invoice.prepaid_credit_invoice');
        }

        $country = $invoice->billingEntity?->country;

        // Rails: Invoice::TAX_INVOICE_LABEL_COUNTRIES.
        if (in_array($country, ['AU', 'AE', 'NZ', 'ID', 'SG'], true)) {
            if ($this->hasAdvanceCharges()) {
                return __('invoice.paid_tax_invoice');
            }

            return __('invoice.document_tax_name');
        }

        if ($this->hasAdvanceCharges()) {
            return __('invoice.paid_invoice');
        }

        return __('invoice.document_name');
    }

    /** Rails: `invoice.advance_charges?` — every fee is a pay-in-advance charge. */
    public function hasAdvanceCharges(): bool
    {
        $fees = $this->invoice->fees;

        return $fees->isNotEmpty()
            && $fees->every(fn (Fee $fee) => $fee->pay_in_advance === true)
            && $fees->every(fn (Fee $fee) => $fee->typeEnum() === FeeType::Charge);
    }

    /**
     * Port of Customer#display_name
     * (name.presence || [firstname, lastname].join || legal_name).
     */
    public function customerDisplayName(): string
    {
        $customer = $this->customer();

        if ($customer === null) {
            return '';
        }

        if (filled($customer->name)) {
            return (string) $customer->name;
        }

        $composed = mb_trim(($customer->firstname ?? '').' '.($customer->lastname ?? ''));

        if ($composed !== '') {
            return $composed;
        }

        return (string) ($customer->legal_name ?? '');
    }

    /** ISO3166 common-name stand-in for the billing entity/customer country. */
    public function countryName(?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        // TODO(port): the ISO3166 gem's full common-name table — the
        // countries the Lago docs/seed data exercise are covered; anything
        // else echoes the alpha-2 code.
        return match (mb_strtoupper($code)) {
            'US' => 'United States',
            'FR' => 'France',
            'DE' => 'Germany',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'PT' => 'Portugal',
            'IE' => 'Ireland',
            'LU' => 'Luxembourg',
            default => $code,
        };
    }

    /**
     * Port of the totals-table applied-tax row label: Rails uses
     * invoice.tax_name (name (rate% on amount)) or, for whole-invoice
     * taxes, invoice.tax_name_only.{tax_code}.
     */
    public function appliedTaxRowLabel(InvoiceAppliedTax $appliedTax): string
    {
        // Port of Invoice::AppliedTax#applied_on_whole_invoice? — the tax
        // codes applicable on the whole invoice render their
        // tax_name_only.{code} label with no amount.
        if (in_array($appliedTax->tax_code, [
            'not_collecting',
            'juris_not_taxed',
            'reverse_charge',
            'customer_exempt',
            'transaction_exempt',
            'juris_has_no_tax',
            'unknown_taxation',
        ], true)) {
            return (string) __('invoice.tax_name_only.'.$appliedTax->tax_code);
        }

        return __('invoice.tax_name', [
            'name' => $appliedTax->tax_name,
            'rate' => $appliedTax->tax_rate,
            'amount' => $this->money($appliedTax->taxable_base_amount_cents, $appliedTax->amount_currency),
        ]);
    }

    /**
     * Rails: `invoice.credit?` — a prepaid-credits invoice (the credits'
     * invoiceable is a wallet). The invoice_type enum is the source here.
     */
    public function isCredit(): bool
    {
        return $this->invoice->isCredit();
    }

    /**
     * Rails: `invoice.progressive_billing?` (invoice_type).
     */
    public function isProgressiveBilling(): bool
    {
        return $this->invoice->typeEnum() === InvoiceType::ProgressiveBilling;
    }

    /**
     * Rails: `invoice.subscription?` — the invoice has recurring
     * invoice_subscriptions.
     */
    public function hasSubscription(): bool
    {
        return $this->invoice->invoiceSubscriptions()->exists();
    }

    /** Port of RoundingHelper.round_decimal_part for the units column. */
    public function units(mixed $units): string
    {
        if ($units === null) {
            return '';
        }

        $value = (float) $units;

        return (string) (floor($value * 1000000) / 1000000);
    }
}
