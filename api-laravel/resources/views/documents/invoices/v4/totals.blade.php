{{-- Port of Rails' v4/_subscription_details.slim totals table (the
     single-subscription branch), including _prepaid_credits. Coupon-kind
     and credit-note rows are TODO(port) pending credit models/kinds. --}}
<table class="total-table" width="100%">
    @if ((int) $invoice->progressive_billing_credit_amount_cents > 0)
        <tr>
            <td class="body-2"></td>
            <td class="body-2">{{ __('invoice.progressive_billing_credit') }}</td>
            <td class="body-2">-{{ $ctx->money($invoice->progressive_billing_credit_amount_cents) }}</td>
        </tr>
    @endif

    {{-- TODO(port): coupon credits (credits.coupon_kind). --}}

    <tr>
        <td class="body-2"></td>
        <td class="body-2">{{ __('invoice.sub_total_without_tax') }}</td>
        <td class="body-2">{{ $ctx->money($invoice->sub_total_excluding_taxes_amount_cents) }}</td>
    </tr>

    @php($appliedTaxes = $invoice->appliedTaxes()->orderByDesc('tax_rate')->get())
    @if ($appliedTaxes->isNotEmpty())
        @foreach ($appliedTaxes as $appliedTax)
            <tr>
                <td class="body-2"></td>
                @if (in_array($appliedTax->tax_code, [
                    'not_collecting', 'juris_not_taxed', 'reverse_charge',
                    'customer_exempt', 'transaction_exempt', 'juris_has_no_tax',
                    'unknown_taxation',
                ], true))
                    <td class="body-2">{{ __('invoice.tax_name_only.'.$appliedTax->tax_code) }}</td>
                    <td class="body-2"></td>
                @else
                    <td class="body-2">{{ __('invoice.tax_name', [
                        'name' => $appliedTax->tax_name,
                        'rate' => $appliedTax->tax_rate,
                        'amount' => $ctx->money($appliedTax->taxable_base_amount_cents, $appliedTax->amount_currency),
                    ]) }}</td>
                    <td class="body-2">{{ $ctx->money($appliedTax->amount_cents, $appliedTax->amount_currency) }}</td>
                @endif
            </tr>
        @endforeach
    @else
        <tr>
            <td class="body-2"></td>
            <td class="body-2">{{ __('invoice.tax_name_with_details', ['name' => 'Tax', 'rate' => 0]) }}</td>
            <td class="body-2">{{ $ctx->money(0) }}</td>
        </tr>
    @endif

    <tr>
        <td class="body-2"></td>
        <td class="body-2">{{ __('invoice.sub_total_with_tax') }}</td>
        <td class="body-2">{{ $ctx->money($invoice->sub_total_including_taxes_amount_cents) }}</td>
    </tr>

    {{-- TODO(port): credit-note credits (credits.credit_note_kind). --}}

    @if ((int) $invoice->prepaid_granted_credit_amount_cents > 0)
        <tr>
            <td class="body-2"></td>
            <td class="body-2">{{ __('invoice.free_credits') }}</td>
            <td class="body-2">-{{ $ctx->money($invoice->prepaid_granted_credit_amount_cents) }}</td>
        </tr>
    @endif
    @if ((int) $invoice->prepaid_purchased_credit_amount_cents > 0)
        <tr>
            <td class="body-2"></td>
            <td class="body-2">{{ __('invoice.prepaid_credits') }}</td>
            <td class="body-2">-{{ $ctx->money($invoice->prepaid_purchased_credit_amount_cents) }}</td>
        </tr>
    @endif
    @if ((int) $invoice->prepaid_granted_credit_amount_cents === 0 && (int) $invoice->prepaid_purchased_credit_amount_cents === 0 && (int) $invoice->prepaid_credit_amount_cents > 0)
        <tr>
            <td class="body-2"></td>
            <td class="body-2">{{ __('invoice.prepaid_credits') }}</td>
            <td class="body-2">-{{ $ctx->money($invoice->prepaid_credit_amount_cents) }}</td>
        </tr>
    @endif

    <tr>
        <td class="body-2"></td>
        <td class="body-1">{{ __('invoice.total') }}</td>
        <td class="body-1">{{ $ctx->money($invoice->total_amount_cents) }}</td>
    </tr>
</table>
