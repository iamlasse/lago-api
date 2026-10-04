{{-- Port of Rails' v4/_graduated.slim (amount_details.graduated_ranges). --}}
@php($ranges = $fee->amount_details['graduated_ranges'] ?? [])
@php($firstRange = ($ranges[0]['to_value'] ?? null) === null ? null : ($ranges[0] ?? null))
@php($lastRange = collect($ranges)->first(fn ($range) => ($range['to_value'] ?? null) === null))
@php($nextRanges = collect($ranges)->filter(fn ($range) => ($range['from_value'] ?? 0) != 0 && ($range['to_value'] ?? null) !== null))

@if ($firstRange !== null)
    <tr class="details">
        <td class="body-2">{{ __('invoice.graduated.fee_per_unit_for_the_first', ['to' => $firstRange['to_value']]) }}</td>
        <td class="body-2">{{ $ctx->units($firstRange['units']) }}</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($firstRange['per_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($firstRange['per_unit_total_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

@foreach ($nextRanges as $range)
    <tr class="details">
        <td class="body-2">{{ __('invoice.graduated.fee_per_unit_for_the_next', ['from' => $range['from_value'], 'to' => $range['to_value']]) }}</td>
        <td class="body-2">{{ $ctx->units($range['units']) }}</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($range['per_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($range['per_unit_total_amount'], $fee->amount_currency) }}</td>
    </tr>
@endforeach

@if ($lastRange !== null)
    <tr class="details">
        <td class="body-2">{{ __('invoice.graduated.fee_per_unit_for_the_last', ['from' => $lastRange['from_value']]) }}</td>
        <td class="body-2">{{ $ctx->units($lastRange['units']) }}</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($lastRange['per_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($lastRange['per_unit_total_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

@if ($firstRange !== null && (float) ($firstRange['flat_unit_amount'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.graduated.flat_fee_for_the_first', ['to' => $firstRange['to_value']]) }}</td>
        <td class="body-2">1</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($firstRange['flat_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($firstRange['flat_unit_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

@foreach ($nextRanges as $range)
    @if ((float) ($range['flat_unit_amount'] ?? 0) > 0)
        <tr class="details">
            <td class="body-2">{{ __('invoice.graduated.flat_fee_for_the_next', ['from' => $range['from_value'], 'to' => $range['to_value']]) }}</td>
            <td class="body-2">1</td>
            <td class="body-2">{{ $ctx->moneyWithPrecision($range['flat_unit_amount'], $fee->amount_currency) }}</td>
            <td class="body-2">
                @foreach ($ctx->taxRates($fee) as $rate)
                    <div>{{ $rate }}</div>
                @endforeach
            </td>
            <td class="body-2">{{ $ctx->moneyWithPrecision($range['flat_unit_amount'], $fee->amount_currency) }}</td>
        </tr>
    @endif
@endforeach

@if ($lastRange !== null && (float) ($lastRange['flat_unit_amount'] ?? 0) > 0)
    <tr class="details">
        <td class="body-2">{{ __('invoice.graduated.flat_fee_for_the_last', ['from' => $lastRange['from_value']]) }}</td>
        <td class="body-2">1</td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($lastRange['flat_unit_amount'], $fee->amount_currency) }}</td>
        <td class="body-2">
            @foreach ($ctx->taxRates($fee) as $rate)
                <div>{{ $rate }}</div>
            @endforeach
        </td>
        <td class="body-2">{{ $ctx->moneyWithPrecision($lastRange['flat_unit_amount'], $fee->amount_currency) }}</td>
    </tr>
@endif

<tr class="details subtotal">
    <td class="body-2">{{ __('invoice.sub_total') }}</td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2"></td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>
