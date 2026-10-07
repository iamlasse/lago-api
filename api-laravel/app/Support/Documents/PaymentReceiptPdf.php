<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Customer;
use App\Support\Currency;
use Carbon\CarbonInterface;
use App\Models\BillingEntity;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\App;

/**
 * View-context for the payment receipt document template (the Blade port of
 * Rails' Slim payment_receipts/v1 template context — receipt number, the
 * payable (invoice / payment request) and the MoneyHelper/I18n helpers the
 * template leans on).
 *
 * TODO(port): the full Rails v1 layout (metadata table, payment-request
 * section, invoice resume partials, logos, EU tax management) — the ported
 * template answers the headline receipt fields only.
 */
final readonly class PaymentReceiptPdf
{
    private const array SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    public function __construct(private PaymentReceipt $receipt) {}

    /** Rails: PaymentReceipts::GeneratePdfService#template ("payment_receipts/v1"). */
    public static function templateName(): string
    {
        return 'documents.payment_receipts.v1';
    }

    /** Render a receipt template under the customer's document locale. */
    public static function render(string $template, PaymentReceipt $receipt): string
    {
        $previous = App::getLocale();

        // Rails: I18n.with_locale(payment.customer.preferred_document_locale).
        $locale = $receipt->customer?->preferredDocumentLocale();

        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            return view($template, [
                'receipt' => $receipt,
                'ctx' => new self($receipt),
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /** Shared view data for the documents footer (page numbering + number). */
    public static function sharedViewData(PaymentReceipt $receipt): array
    {
        return [
            'receipt' => $receipt,
            'number' => (string) $receipt->number,
            'ctx' => new self($receipt),
        ];
    }

    // -- Template convenience accessors ---------------------------------------

    public function customer(): ?Customer
    {
        return $this->receipt->customer;
    }

    public function billingEntity(): ?BillingEntity
    {
        return $this->receipt->billingEntity;
    }

    /** Rails: payment.payable — the Invoice or PaymentRequest paid. */
    public function payable(): ?object
    {
        return $this->receipt->payment?->payable;
    }

    /** Port of MoneyHelper.format (see InvoicePdf#money). */
    public function money(null|int|float|string $amountCents, ?string $currency = null): string
    {
        $currency ??= $this->receipt->payment?->amount_currency;
        $amount = (float) ($amountCents ?? 0) / Currency::subunitToUnit($currency);
        $formatted = number_format($amount, Currency::exponent($currency), '.', ',');

        $symbol = self::SYMBOLS[mb_strtoupper($currency ?? '')] ?? null;

        return $symbol === null
            ? sprintf('%s %s', $currency, $formatted)
            : sprintf('%s%s', $symbol, $formatted);
    }

    /** Port of I18n.l(date, format: :default) as rendered by the template. */
    public function formatDate(?CarbonInterface $date): string
    {
        return $date?->isoFormat('LL') ?? '';
    }
}
