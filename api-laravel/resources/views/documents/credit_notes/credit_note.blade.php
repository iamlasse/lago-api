{{-- Port of Rails' app/views/templates/credit_notes/credit_note.slim (the
     credit note document). TODO(port): the full layout fidelity — customer
     displayable metadata column, billing-entity logo, the items-table
     sub-parts (coupon adjustments, wallet prepaid-credit items, EU tax
     management, powered-by logo) and the self-billed footer text placement.
     The ported template answers the headline structure: document name,
     numbers/dates, credit from / credit to blocks, the items table and the
     totals, over the shared document styles. --}}
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('credit_note.document_name') }}</title>
    <style>
        body {
            box-sizing: border-box;
            margin: 0;
            padding: 32px;
            color: #1e293b;
            font-family: Inter, sans-serif;
            font-size: 12px;
            line-height: 20px;
        }

        .wrapper { max-width: 720px; margin: 0 auto; }

        .credit-note-title { margin: 0 0 24px; font-size: 24px; font-weight: 600; line-height: 36px; }
        .title-2 { margin: 0 0 4px; font-size: 18px; font-weight: 600; }

        .mb-24 { margin-bottom: 24px; }
        .overflow-auto::after { content: ''; display: table; clear: both; }
        .information-column { width: 50%; float: left; }

        .information-table { width: 100%; border-collapse: collapse; }
        .information-table td { vertical-align: top; padding: 2px 0; }

        .body-1 { color: #66758f; }
        .body-2 { color: #1e293b; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .items-table th { text-align: left; color: #66758f; font-weight: 400; border-bottom: 1px solid #d9dee7; padding: 4px 0; }
        .items-table td { border-bottom: 1px solid #eef2f7; padding: 8px 0; vertical-align: top; }
        .items-table .amount { text-align: right; white-space: nowrap; }

        .totals-table { width: 50%; border-collapse: collapse; margin-left: auto; }
        .totals-table td { padding: 4px 0; }
        .totals-table .amount { text-align: right; white-space: nowrap; }
        .totals-table .total-row td { font-weight: 600; border-top: 1px solid #d9dee7; }
    </style>
</head>
<body>
<div class="wrapper">
    <h1 class="credit-note-title">{{ __('credit_note.document_name') }}</h1>

    <div class="mb-24 overflow-auto">
        <div class="information-column">
            <table class="information-table">
                <tr>
                    <td class="body-1">{{ __('credit_note.credit_note_number') }}</td>
                    <td class="body-2">{{ $creditNote->number }}</td>
                </tr>
                <tr>
                    <td class="body-1">{{ __('credit_note.invoice_number') }}</td>
                    <td class="body-2">{{ $ctx->invoice()?->number }}</td>
                </tr>
                @if($ctx->invoice()?->purchase_order_number)
                    <tr>
                        <td class="body-1">{{ __('credit_note.purchase_order_number') }}</td>
                        <td class="body-2">{{ $ctx->invoice()->purchase_order_number }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="body-1">{{ __('credit_note.issue_date') }}</td>
                    <td class="body-2">{{ $ctx->date($creditNote->issuing_date) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="mb-24 overflow-auto">
        <div class="information-column">
            <div class="body-1">{{ __('credit_note.credit_from') }}</div>
            <div class="body-2">{{ $ctx->billingEntity()?->legal_name ?: $ctx->billingEntity()?->name }}</div>
            @if($ctx->billingEntity()?->legal_number)
                <div class="body-2">{{ $ctx->billingEntity()->legal_number }}</div>
            @endif
            <div class="body-2">{{ $ctx->billingEntity()?->address_line1 }}</div>
            <div class="body-2">{{ $ctx->billingEntity()?->address_line2 }}</div>
            <div class="body-2">
                {{ trim(($ctx->billingEntity()?->zipcode ?? '').' '.($ctx->billingEntity()?->city ?? '')) }}
            </div>
            @if($ctx->billingEntity()?->country)
                <div class="body-2">{{ $ctx->billingEntity()->country }}</div>
            @endif
            @if($ctx->billingEntity()?->tax_identification_number)
                <div class="body-2">{{ __('invoice.tax_identification_number', ['tax_identification_number' => $ctx->billingEntity()->tax_identification_number]) }}</div>
            @endif
        </div>
        <div class="information-column">
            <div class="body-1">{{ __('credit_note.credit_to') }}</div>
            <div class="body-2">{{ $ctx->customer()?->displayName() }}</div>
            @if($ctx->customer()?->legal_number)
                <div class="body-2">{{ $ctx->customer()->legal_number }}</div>
            @endif
            <div class="body-2">{{ $ctx->customer()?->address_line1 }}</div>
            <div class="body-2">{{ $ctx->customer()?->address_line2 }}</div>
            <div class="body-2">
                {{ trim(($ctx->customer()?->zipcode ?? '').' '.($ctx->customer()?->city ?? '')) }}
            </div>
            @if($ctx->customer()?->country)
                <div class="body-2">{{ $ctx->customer()->country }}</div>
            @endif
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>{{ __('credit_note.item') }}</th>
                <th class="amount">{{ __('credit_note.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($creditNote->items as $item)
                <tr>
                    <td>
                        {{ $ctx->invoice() !== null ? __('credit_note.amount') : '' }}
                        {{ $item->fee?->charge?->invoice_display_name ?? $item->fee?->charge?->billableMetric?->name ?? $item->fee?->addOn?->name ?? $item->fee?->subscription?->name ?? $item->description ?? __('credit_note.subscription') }}
                    </td>
                    <td class="amount">{{ $ctx->money($item->amount_cents) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals-table">
        <tr>
            <td class="body-1">{{ __('credit_note.sub_total_without_tax') }}</td>
            <td class="amount">{{ $ctx->money($creditNote->subTotalExcludingTaxesAmountCents()) }}</td>
        </tr>
        <tr>
            <td class="body-1">{{ __('credit_note.coupon_adjustment') }}</td>
            <td class="amount">{{ $ctx->money($creditNote->coupons_adjustment_amount_cents) }}</td>
        </tr>
        <tr>
            <td class="body-1">{{ __('credit_note.tax_rate') }}</td>
            <td class="amount">{{ $creditNote->taxes_amount_cents > 0 ? \App\Support\MoneyMath::toF($creditNote->taxes_rate).'%' : '0.0%' }}</td>
        </tr>
        <tr>
            <td class="body-1">{{ __('credit_note.tax') }}</td>
            <td class="amount">{{ $ctx->money($creditNote->taxes_amount_cents) }}</td>
        </tr>
        <tr class="total-row">
            <td>{{ __('credit_note.total') }}</td>
            <td class="amount">{{ $ctx->money($creditNote->total_amount_cents) }}</td>
        </tr>
    </table>

    @if($selfBilledFooter ?? false)
        <div class="body-1" style="margin-top: 32px;">
            {{ __('credit_note.self_billed.footer') }}
        </div>
    @endif
</div>
</body>
</html>
