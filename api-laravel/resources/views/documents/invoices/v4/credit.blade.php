{{-- Port of Rails' v4/_credit.slim — the prepaid-credits invoice resume. --}}
<table class="invoice-resume-table" width="100%">
    <tr>
        <td class="body-2">{{ __('invoice.item') }}</td>
        <td class="body-2">{{ __('invoice.units') }}</td>
        <td class="body-2">{{ __('invoice.unit_price') }}</td>
        <td class="body-2">{{ __('invoice.amount') }}</td>
    </tr>
    @php($creditFee = $invoice->fees->first())
    @if ($creditFee !== null)
        <tr>
            @php($walletName = $creditFee->invoiceable?->wallet?->name ?? null)
            @if (filled($creditFee->invoiceable?->name))
                <td class="body-1">{{ $creditFee->invoiceable->name }}</td>
            @elseif (filled($walletName))
                <td class="body-1">{{ __('invoice.prepaid_credits_with_value', ['wallet_name' => $walletName]) }}</td>
            @else
                <td class="body-1">{{ __('invoice.prepaid_credits') }}</td>
            @endif
            <td class="body-2">{{ $creditFee->invoiceable?->credit_amount }}</td>
            <td class="body-2">{{ $creditFee->invoiceable?->wallet?->rate_amount }}</td>
            <td class="body-2">{{ $ctx->money($creditFee->amount_cents) }}</td>
        </tr>
    @endif
</table>

<table class="total-table" width="100%">
    <tr>
        <td class="body-2"></td>
        <td class="body-1">{{ __('invoice.total') }}</td>
        <td class="body-1">{{ $ctx->money($invoice->total_amount_cents) }}</td>
    </tr>
</table>
