{{-- Port of Rails' v4/_charge_percentage.slim. --}}
@php($details = $fee->amount_details ?? [])

@if ((float) ($details['free_units'] ?? 0) > 0 || (float) ($details['free_events'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.percentage.free_units_per_transaction', ['count' => 1]) }}</td>
        <td class="body-2">{{ $details['free_units'] ?? '' }}</td>
        <td class="body-2">{{ $ctx->money(0, $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->money(0, $fee->amount_currency) }}</td>
    </tr>
@endif

@if ((float) ($details['paid_units'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.percentage.percentage_rate_on_amount') }}</td>
        <td class="body-2">{{ $details['paid_units'] }}</td>
        <td class="body-2">{{ ($details['rate'] ?? '') }}%</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($details['per_unit_total_amount'] ?? 0, $fee->amount_currency) }}</td>
    </tr>
@endif

@if ((float) ($details['fixed_fee_total_amount'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.percentage.fee_per_transaction') }}</td>
        <td class="body-2">{{ $details['paid_events'] ?? '' }}</td>
        <td class="body-2">{{ $ctx->money($details['fixed_fee_total_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->money($details['fixed_fee_total_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

@if ((float) ($details['min_max_adjustment_total_amount'] ?? 0) != 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.percentage.adjustment_per_transaction') }}</td>
        <td class="body-2">1</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($details['min_max_adjustment_total_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($details['min_max_adjustment_total_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

<tr class="details subtotal">
    <td class="body-2">{{ __('invoice.sub_total') }}</td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>
