{{-- Port of Rails' v4/_default_fee.slim plus the per-charge-model breakdown
     partials (_graduated, _package, _percentage, _volume,
     _charge_percentage) and _conversion_row. Pricing-unit conversion rows
     are TODO(port) (pricing units unported). --}}
<tr class="fee">
    <td>
        <div class="body-1">{{ $ctx->feeTitle($fee) }}</div>
        @if ($fee->charge?->billableMetric?->aggregation_type === 'weighted_sum_agg')
            {{-- TODO(port): interval wording via the subscription plan. --}}
            <div class="body-3">{{ __('invoice.units_prorated_per_period', ['period' => 'month']) }}</div>
        @endif
        @if ($fee->charge?->charge_model === 3)
            <div class="body-3">{{ __('invoice.total_events', ['count' => $fee->events_count]) }}</div>
        @endif
        @if ($fee->charge?->prorated === true || $fee->fixed_charge?->prorated === true)
            <div class="body-3">{{ __('invoice.fee_prorated') }}</div>
        @endif
    </td>
    <td class="body-2">{{ $ctx->units($fee->units) }}</td>
    <td class="body-2">{{ $ctx->moneyWithPrecision($fee->precise_unit_amount, $fee->amount_currency) }}</td>
    <td class="body-2">
        @foreach ($ctx->taxRates($fee) as $rate)
            <div>{{ $rate }}</div>
        @endforeach
    </td>
    <td class="body-2">{{ $ctx->money($fee->amount_cents) }}</td>
</tr>

@php($chargeModel = (int) ($fee->charge?->charge_model ?? -1))
@if ($chargeModel === 1)
    @include('documents.invoices.v4.graduated', ['fee' => $fee])
@elseif ($chargeModel === 2)
    @include('documents.invoices.v4.package', ['fee' => $fee])
@elseif ($chargeModel === 3)
    @include('documents.invoices.v4.percentage', ['fee' => $fee])
@elseif ($chargeModel === 4)
    @include('documents.invoices.v4.volume', ['fee' => $fee])
@elseif ($chargeModel === 5)
    @include('documents.invoices.v4.charge-percentage', ['fee' => $fee])
@endif
