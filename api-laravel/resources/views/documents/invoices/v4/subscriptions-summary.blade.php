{{-- Port of Rails' v4/_subscriptions_summary.slim — the multi-subscription
     resume. Per-subscription commitment fees are included; progressive
     billing credits per subscription are TODO(port). --}}
<table class="invoice-resume-table" width="100%">
    <tr>
        <td class="body-2">{{ __('invoice.item') }}</td>
        <td class="body-2">{{ __('invoice.amount') }}</td>
    </tr>
    @foreach ($invoice->invoiceSubscriptions()->with('subscription.plan')->orderBy('created_at')->get() as $invoiceSubscription)
        @php($subscription = $invoiceSubscription->subscription)
        @php($subscriptionTotalCents = (int) $invoice->fees()->where('subscription_id', $subscription->id)->sum('amount_cents'))
        <tr>
            <td class="body-1">{{ $ctx->subscriptionInvoiceName($subscription) }}</td>
            <td class="body-2">{{ $ctx->money($subscriptionTotalCents) }}</td>
        </tr>
        @php($commitmentFee = $invoice->fees()
            ->where('subscription_id', $subscription->id)
            ->where('fee_type', 4)
            ->first())
        @if ($commitmentFee !== null)
            <tr>
                <td class="body-1">{{ $ctx->feeInvoiceName($commitmentFee) }}</td>
                <td class="body-2">{{ $ctx->money($commitmentFee->amount_cents) }}</td>
            </tr>
        @endif
    @endforeach
</table>

@include('documents.invoices.v4.totals')
