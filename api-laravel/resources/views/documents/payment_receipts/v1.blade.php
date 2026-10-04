{{-- Port of Rails' app/views/templates/payment_receipts/v1.slim (payment
     receipt document). TODO(port): the full v1 layout — the metadata table's
     customer metadata column, payment method display name, logo/branding
     partials, the payable invoice resume partials (credit / one_off /
     advance charges / subscription details), EU tax management, custom
     sections and the payment-request variant. The ported template answers
     the headline receipt fields (document name, number, invoice number,
     payment date, bill from / bill to, amount paid) over the shared invoice
     document styles. --}}
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('payment_receipt.document_name') }}</title>
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

        .invoice-title {
            margin: 0 0 24px;
            font-size: 24px;
            font-weight: 600;
            line-height: 36px;
        }

        .title-2 { margin: 0 0 4px; font-size: 18px; font-weight: 600; }

        .mb-24 { margin-bottom: 24px; }

        .information-table { width: 100%; border-collapse: collapse; }
        .information-table td { vertical-align: top; padding: 2px 0; }

        .body-1 { color: #66758f; }
        .body-2 { color: #1e293b; }
    </style>
</head>
<body>
<div class="wrapper">
    <h1 class="invoice-title">{{ __('payment_receipt.document_name') }}</h1>

    <table class="information-table mb-24">
        <tr>
            <td class="body-1">{{ __('payment_receipt.number') }}</td>
            <td class="body-2">{{ $receipt->number }}</td>
        </tr>
        @php($payable = $ctx->payable())
        @if($payable instanceof \App\Models\Invoice)
            <tr>
                <td class="body-1">{{ __('invoice.invoice_number') }}</td>
                <td class="body-2">{{ $payable->number }}</td>
            </tr>
        @endif
        <tr>
            <td class="body-1">{{ __('payment_receipt.payment_date') }}</td>
            <td class="body-2">{{ $ctx->formatDate($receipt->created_at) }}</td>
        </tr>
    </table>

    <table class="information-table mb-24">
        <tr>
            <td class="body-1">{{ __('invoice.bill_from') }}</td>
            <td class="body-2">
                {{ $ctx->billingEntity()?->legal_name ?: $ctx->billingEntity()?->name }}
            </td>
        </tr>
        <tr>
            <td class="body-1">{{ __('invoice.bill_to') }}</td>
            <td class="body-2">{{ $ctx->customer()?->displayName() }}</td>
        </tr>
    </table>

    <div class="mb-24">
        <h2 class="title-2">{{ $ctx->money($receipt->payment?->amount_cents) }}</h2>
        <div class="body-1">
            {{ __('payment_receipt.paid_on', [
                'date' => $ctx->formatDate($receipt->created_at),
                'total_due_amount' => $ctx->money(
                    $payable instanceof \App\Models\Invoice
                        ? $payable->total_due_amount_cents
                        : ($payable?->amount_cents ?? 0) - ($receipt->payment?->amount_cents ?? 0),
                    $payable?->currency ?? null,
                ),
            ]) }}
        </div>
    </div>
</div>
</body>
</html>
