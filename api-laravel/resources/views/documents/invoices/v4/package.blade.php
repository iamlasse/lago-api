{{-- Port of Rails' v4/_package.slim. --}}
@php($details = $fee->amount_details ?? [])

@if ((float) ($details['free_units'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.package.free_units_for_the_first', ['count' => $details['free_units']]) }}</td>
        <td class="body-2">{{ $details['free_units'] }}</td>
        <td class="body-2">{{ $ctx->money(0, $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->money(0, $fee->amount_currency) }}</td>
    </tr>
@endif

<tr class="details">
    <td class="body-2">{{ __('invoice.package.fee_per_package') }}</td>
    <td class="body-2">{{ $details['paid_units'] ?? '' }}</td>
    <td class="body-2">{{ __('invoice.package.fee_per_package_unit_price', [
        'amount' => $ctx->moneyWithPrecision($details['per_package_unit_amount'] ?? 0, $fee->amount_currency),
        'package_size' => $details['per_package_size'] ?? '',
    ]) }}</td>
    <td class="body-2">
        @foreach ($ctx->taxRates($fee) as $rate)
            <div>{{ $rate }}</div>
        @endforeach
    </td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>

<tr class="details subtotal">
    <td class="body-2">{{ __('invoice.sub_total') }}</td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>
