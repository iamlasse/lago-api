{{-- Port of Rails' v4/_volume.slim. --}}
@php($details = $fee->amount_details ?? [])

<tr class="details">
    <td class="body-2">{{ __('invoice.volume.fee_per_unit') }}</td>
    <td class="body-2">{{ $ctx->units($fee->units) }}</td>
    <td class="body-2">{{ $ctx->moneyWithPrecision($details['per_unit_amount'] ?? 0, $fee->amount_currency) }}</td>
    <td class="body-2">
        @foreach ($ctx->taxRates($fee) as $rate)
            <div>{{ $rate }}</div>
        @endforeach
    </td>
    <td class="body-2">{{ $ctx->moneyWithPrecision($details['per_unit_total_amount'] ?? 0, $fee->amount_currency) }}</td>
</tr>

@if ((float) ($details['flat_unit_amount'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.volume.flat_fee_for_all_units') }}</td>
        <td class="body-2">1</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($details['flat_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->money($details['flat_unit_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

<tr class="details subtotal">
    <td class="body-2">{{ __('invoice.sub_total') }}</td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>
