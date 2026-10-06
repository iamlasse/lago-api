<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Invoice;
use App\Models\Customer;
use App\Support\Currency;
use App\Models\CreditNote;
use Carbon\CarbonInterface;
use App\Models\BillingEntity;
use Illuminate\Support\Facades\App;

/**
 * Port of Rails' credit-note document context
 * (app/views/templates/credit_notes/credit_note.slim + SlimHelper) — the
 * template selection, locale-scoped rendering and the money/date helpers the
 * credit-note layout shares with the invoice document.
 */
final class CreditNotePdf
{
    /** Rails MoneyHelper::SYMBOLS_CURRENCIES — currencies shown with a symbol. */
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    public function __construct(private readonly CreditNote $creditNote) {}

    /** Rails: CreditNotes::GeneratePdfService#template. */
    public static function templateName(CreditNote $creditNote): string
    {
        return $creditNote->invoice?->self_billed
            ? 'documents.credit_notes.self_billed'
            : 'documents.credit_notes.credit_note';
    }

    /** Render a credit-note template under the customer's document locale. */
    public static function render(string $template, CreditNote $creditNote): string
    {
        $previous = App::getLocale();
        // Rails: I18n.with_locale(credit_note.customer.preferred_document_locale).
        $locale = $creditNote->customer?->preferredDocumentLocale();
        App::setLocale($locale !== null && $locale !== '' ? $locale : 'en');

        try {
            return view($template, [
                'creditNote' => $creditNote,
                'ctx' => new self($creditNote),
            ])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /** Shared view data for the documents footer (page numbering + number). */
    public static function sharedViewData(CreditNote $creditNote): array
    {
        return [
            'creditNote' => $creditNote,
            'number' => (string) $creditNote->number,
            'ctx' => new self($creditNote),
        ];
    }

    // -- Template convenience accessors ---------------------------------------

    public function creditNote(): CreditNote
    {
        return $this->creditNote;
    }

    public function customer(): ?Customer
    {
        return $this->creditNote->customer;
    }

    public function invoice(): ?Invoice
    {
        return $this->creditNote->invoice;
    }

    /** Rails: credit_note.billing_entity (the has_one :through delegate). */
    public function billingEntity(): ?BillingEntity
    {
        return $this->creditNote->billingEntity;
    }

    // -- Money / dates (same helpers as the invoice document) -----------------

    public function money(null|int|float|string $amountCents, ?string $currency = null): string
    {
        $currency = $currency ?? $this->creditNote->currency();
        $amount = (float) ($amountCents ?? 0) / Currency::subunitToUnit($currency);
        $formatted = number_format($amount, Currency::exponent($currency), '.', ',');

        $symbol = self::SYMBOLS[mb_strtoupper($currency ?? '')] ?? null;

        return $symbol === null
            ? sprintf('%s %s', $currency, $formatted)
            : sprintf('%s%s', $symbol, $formatted);
    }

    public function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value : \Illuminate\Support\Carbon::parse($value);

        return $date->translatedFormat('M d, Y');
    }
}
