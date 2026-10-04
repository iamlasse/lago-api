{{-- Port of Rails' v4/_subscription_details.slim. Deviations (TODO(port)):
     recurring-fee breakdown pages, presentation breakdowns, charge-filter
     grouping and true-up rows are later milestones; billing-period fee
     groups are collapsed to one group per subscription. --}}
@php($subscriptions = $invoice->invoiceSubscriptions()->with('subscription.plan')->get())
@foreach ($subscriptions as $invoiceSubscription)
    @php($subscription = $invoiceSubscription->subscription)
    @php($fees = $invoice->fees()->where('subscription_id', $subscription->id)->get())

    <h2 class="title-2 mb-24 {{ isset($pageBreak) && $pageBreak ? 'invoice-details-title' : '' }}">
        {{ __('invoice.details', ['resource' => $ctx->subscriptionInvoiceName($subscription)]) }}
    </h2>

    <div class="invoice-resume overflow-auto">
        <table class="invoice-resume-table" width="100%">
            {{-- Period header row (billing-period grouping TODO(port)). --}}
            <tr class="first_child">
                <td class="body-2">
                    {{ trim($ctx->date($invoiceSubscription->from_datetime).' - '.$ctx->date($invoiceSubscription->to_datetime)) }}
                </td>
                <td class="body-2">{{ __('invoice.units') }}</td>
                <td class="body-2">{{ __('invoice.unit_price') }}</td>
                <td class="body-2">{{ __('invoice.tax_rate') }}</td>
                <td class="body-2">{{ __('invoice.amount') }}</td>
            </tr>

            {{-- 1. Subscription fee. --}}
            @php($subscriptionFee = $fees->first(fn ($fee) => $fee->typeEnum()?->name === 'Subscription'))
            @php($chargeAmountCents = (int) $fees->where('fee_type', 0)->sum('amount_cents'))
            @php($fixedChargeAmountCents = (int) $fees->where('fee_type', 5)->sum('amount_cents'))
            @php($showSubscriptionFee = $chargeAmountCents === 0 && $fixedChargeAmountCents === 0
                || (int) ($subscriptionFee?->amount_cents ?? 0) > 0)
            @if ($subscriptionFee !== null && $showSubscriptionFee)
                <tr class="fee">
                    @if (filled($subscriptionFee->invoice_display_name))
                        <td class="body-1">{{ $subscriptionFee->invoice_display_name }}</td>
                    @else
                        <td class="body-1">{{ __('invoice.subscription_interval', [
                            'plan_interval' => $ctx->planIntervalLabel($subscription->plan),
                            'plan_name' => $ctx->planInvoiceName($subscription->plan),
                        ]) }}</td>
                    @endif
                    <td class="body-2">{{ $subscriptionFee->units ?? 1 }}</td>
                    <td class="body-2">{{ $ctx->money($subscriptionFee->unit_amount_cents ?? 0, $subscriptionFee->amount_currency) }}</td>
                    <td class="body-2">
                        @foreach ($ctx->taxRates($subscriptionFee) as $rate)
                            <div>{{ $rate }}</div>
                        @endforeach
                    </td>
                    <td class="body-2">{{ $ctx->money($subscriptionFee->amount_cents) }}</td>
                </tr>
            @endif

            {{-- 2. Fixed charge fees (detailed grouping TODO(port)). --}}
            @foreach ($fees->filter(fn ($fee) => $fee->typeEnum()?->name === 'FixedCharge') as $fee)
                @include('documents.invoices.v4.fee', ['fee' => $fee])
            @endforeach

            {{-- 3. Charge fees (filter grouping TODO(port)). --}}
            @foreach ($fees->filter(fn ($fee) => $fee->typeEnum()?->name === 'Charge')->groupBy('charge_id') as $chargeFees)
                @foreach ($chargeFees as $fee)
                    @include('documents.invoices.v4.fee', ['fee' => $fee])
                @endforeach
            @endforeach

            {{-- 4. Commitment fee. --}}
            @php($commitmentFee = $fees->first(fn ($fee) => $fee->typeEnum()?->name === 'Commitment'))
            @if ($commitmentFee !== null)
                <tr class="fee">
                    <td class="body-1">{{ $ctx->feeInvoiceName($commitmentFee) }}</td>
                    <td class="body-2">1</td>
                    <td class="body-2">{{ $ctx->money($commitmentFee->amount_cents) }}</td>
                    <td class="body-2">
                        @foreach ($ctx->taxRates($commitmentFee) as $rate)
                            <div>{{ $rate }}</div>
                        @endforeach
                    </td>
                    <td class="body-2">{{ $ctx->money($commitmentFee->amount_cents) }}</td>
                </tr>
            @endif
        </table>
    </div>
@endforeach

{{-- Total section. --}}
<div class="invoice-resume overflow-auto">
    @if ($invoice->subscriptions()->count() === 1)
        @include('documents.invoices.v4.totals')
    @else
        <table class="total-table" width="100%">
            <tr>
                <td class="body-2"></td>
                <td class="body-1">{{ __('invoice.total') }}</td>
                <td class="body-1">{{ $ctx->money($invoice->total_amount_cents) }}</td>
            </tr>
        </table>
    @endif
</div>

{{-- TODO(port): recurring-fee breakdown pages and presentation breakdowns. --}}
