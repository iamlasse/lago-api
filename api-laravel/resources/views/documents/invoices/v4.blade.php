{{-- Port of Rails' app/views/templates/invoices/v4.slim (invoice document v4).
     The CSS is verbatim; the markup mirrors the Slim structure. Documented
     deviations (TODO(port) markers inline): the one_off / charge /
     fixed_charge / self_billed template variants, progressive-billing
     details, recurring-fee breakdown pages, presentation breakdowns,
     charge-filter fee grouping and true-up rows are later milestones. --}}
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice</title>
    <style>
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 100;
        font-display: swap;
        src: local("Inter-Thin");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 100;
        font-display: swap;
        src: local("Inter-ThinItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 200;
        font-display: swap;
        src: local("Inter-ExtraLight");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 200;
        font-display: swap;
        src: local("Inter-ExtraLightItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 300;
        font-display: swap;
        src: local("Inter-Light");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 300;
        font-display: swap;
        src: local("Inter-LightItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 400;
        font-display: swap;
        src: local("Inter-Regular");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 400;
        font-display: swap;
        src: local("Inter-Italic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 500;
        font-display: swap;
        src: local("Inter-Medium");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 500;
        font-display: swap;
        src: local("Inter-MediumItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 600;
        font-display: swap;
        src: local("Inter-SemiBold");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 600;
        font-display: swap;
        src: local("Inter-SemiBoldItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 700;
        font-display: swap;
        src: local("Inter-Bold");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 700;
        font-display: swap;
        src: local("Inter-BoldItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 800;
        font-display: swap;
        src: local("Inter-ExtraBold");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 800;
        font-display: swap;
        src: local("Inter-ExtraBoldItalic");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  normal;
        font-weight: 900;
        font-display: swap;
        src: local("Inter-Black");
      }
      @font-face {
        font-family: 'Inter';
        font-style:  italic;
        font-weight: 900;
        font-display: swap;
        src: local("Inter-BlackItalic");
      }

      /* ----------------------- variable ----------------------- */
      :root {
        --border-color: #D9DEE7;
      }

      @font-face {
        font-family: 'Inter var';
        font-style: normal;
        font-weight: 100 900;
        font-display: swap;
        src: local('Inter-roman') format('woff2');
        font-named-instance: 'Regular';
      }

      @font-face {
        font-family: 'Inter var';
        font-style: italic;
        font-weight: 100 900;
        font-display: swap;
        src: local('Inter-italic') format('woff2');
        font-named-instance: 'Italic';
      }
      h1, h2, p { margin: 0; padding: 0; }
      html { font-family: Inter, sans-serif; }
      h1 { color: #19212e; font-weight: 700; font-size: 24px; line-height: 32px; }
      h2 {
        color: #19212e;
        font-weight: 700;
        font-size: 18px;
        line-height: 24px;
      }
      .body-1 {
        color: #19212e;
        font-weight: 600;
        font-size: 10px;
        line-height: 16px;
      }
      .body-2 {
        color: #19212e;
        font-weight: 400;
        font-size: 10px;
        line-height: 16px;
      }
      .body-3 {
        color: #66758f;
        font-weight: 400;
        font-size: 9px;
        line-height: 16px;
      }

      .mb-4 {
        margin-bottom: 4px;
      }
      .mb-24 {
        margin-bottom: 24px;
      }
      .mt-24 {
        margin-top: 24px;
      }

      .overflow-auto {
        overflow: auto;
      }
      tr {
        break-inside: avoid;
      }

      .invoice-title {
        display: inline;
      }
      .header-logo {
        float: right;
        max-height: 32px;
      }

      .invoice-information-column {
        float: left;
        width: 50%;
      }
      .invoice-information-table tr td:first-child {
        padding: 0 16px 0 0;
        white-space: nowrap;
        width: 1%;
      }
      .invoice-information-table tr td:last-child {
        width: 55%;
      }
      .invoice-information-table, tr td {
        text-wrap: normal;
        word-wrap: break-word;
        vertical-align: top;
      }
      .invoice-information-table {
        border-collapse: collapse;
        width: 100%;
      }

      .billing-information-column {
        float: left;
        width: 50%;
      }

      .invoice-resume-table tr td {
        padding-top: 4px;
        padding-bottom: 4px;
        text-align: right;
        word-wrap: break-word;
      }
      .invoice-resume-table td:first-child {
        width: 48%;
        text-align: left;
      }
      .invoice-resume-table td:nth-child(2) {
        width: 14.5%;
        max-width: 10vw;
      }
      .invoice-resume-table td:nth-child(3) {
        width: 12.5%;
        max-width: 10vw;
      }
      .invoice-resume-table td:nth-child(4) {
        width: 12.5%;
      }
      .invoice-resume-table td:nth-child(5) {
        width: 12.5%;
        max-width: 10vw;
      }

      .invoice-resume-table tr.first_child td {
        color: #66758F;
      }

      .invoice-resume-table tr.charge-name td {
        padding-bottom: 0;
        color: #19212e;
      }

      .invoice-resume-table tr.details td {
        color: #66758F;
        padding-top: 4px;
        padding-bottom: 4px;
      }

      .invoice-resume-table tr.details td:first-child {
        padding-left: 8px;
      }

      .invoice-resume-table tr.details.subtotal td {
        color: #19212e;
      }

      .invoice-resume-table tr.fee:first-child {
        border-top: none;
      }
      /* Each first tr representing fee draws border above it */
      .invoice-resume-table tr.fee {
        border-top: 1px solid var(--border-color);
      }
      .invoice-resume-table tr:last-child {
        border-bottom: 1px solid var(--border-color);
      }

      .invoice-resume-table tr.fee td {
        padding-top: 8px;
      }
      /* If tr has next element tr.fee means that current fee info ended and we need bigger padding */
      .invoice-resume-table tr:has(+ tr.fee) td {
        padding-bottom: 8px;
      }
      .invoice-resume-table tr:last-child td {
        padding-bottom: 8px;
      }

      .invoice-resume table {
        border-collapse: collapse;
      }
      .invoice-resume .total-table tr td {
        padding-top: 8px;
        padding-bottom: 8px;
        text-align: right;
      }
      .invoice-resume .total-table td:first-child {
        width: 50%;
      }
      .invoice-resume .total-table tr:not(:last-child) td:nth-child(2) {
        border-bottom: 1px solid var(--border-color);
        text-align: left;
        width: 35%;
      }
      .invoice-resume .total-table tr:not(:last-child) td:nth-child(3) {
        border-bottom: 1px solid var(--border-color);
        text-align: right;
        width: 15%;
      }
      .invoice-resume .total-table tr:last-child td:nth-child(2) {
        text-align: left;
        width: 25%;
      }
      .invoice-resume .total-table tr:last-child td:nth-child(3) {
        text-align: right;
        width: 25%;
      }

      .invoice-details-title {
        page-break-before: always;
      }

      .breakdown-details table {
        border-collapse: collapse;
      }
      .breakdown-details {
        margin-top: -15px;
      }
      .breakdown-details-table tr td {
        padding-bottom: 8px;
        padding-top: 8px;
      }
      .breakdown-details-table tr td:last-child {
        text-align: right;
      }
      .breakdown-details-table tr td {
        border-bottom: 1px solid var(--border-color);
      }
      .breakdown-details-table tr:first-child td {
        border-top: 1px solid var(--border-color);
      }
      .breakdown-details.presentation-breakdowns {
        overflow: visible;
      }
      .presentation-breakdowns-page {
        page-break-before: always;
        padding-top: 24px;
      }
      .presentation-breakdowns .breakdown-details-table tr td {
        border-bottom: none;
        border-top: none;
        color: #66758f;
        padding-bottom: 8px;
        padding-top: 0;
      }
      .presentation-breakdowns .breakdown-details-table tr.fee td {
        color: #19212e;
        padding-bottom: 6px;
        padding-top: 8px;
      }
      .presentation-breakdowns .breakdown-details-table tr.section-end td {
        border-bottom: 1px solid #d9dee7;
        padding-bottom: 8px;
      }
      .presentation-breakdowns .breakdown-details-table tr.presentation-group td:first-child {
        padding-left: 8px;
      }

      .powered-by {
        width: 100%;
        text-align: right;
      }
      .powered-by span {
        color: #8c95a6;
      }
      .powered-by img {
        width: 37px;
        height: 11px;
        vertical-align: middle;
        margin-top: 2px;
      }
      .alert {
        display: flex;
        flex-direction: row;
        align-items: center;
        padding: 16px;
        gap: 16px;
        background: #F3F4F6;
        border-radius: 12px;
      }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="mb-24">
        <h1 class="invoice-title">{{ $ctx->documentInvoiceName() }}</h1>
        @if ($ctx->billingEntity()?->logo)
            <img class="header-logo" src="{{ $ctx->billingEntity()->logo }}" alt="">
        @endif
    </div>

    <div class="mb-24 overflow-auto">
        <div class="invoice-information-column">
            <table class="invoice-information-table">
                <tr>
                    <td class="body-1">{{ __('invoice.invoice_number') }}</td>
                    <td class="body-2">{{ $invoice->number }}</td>
                </tr>
                @if (filled($invoice->purchase_order_number))
                    <tr>
                        <td class="body-1">{{ __('invoice.purchase_order_number') }}</td>
                        <td class="body-2">{{ $invoice->purchase_order_number }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="body-1">{{ __('invoice.issue_date') }}</td>
                    <td class="body-2">{{ $ctx->date($invoice->issuing_date) }}</td>
                </tr>
                <tr>
                    <td class="body-1">{{ __('invoice.payment_term') }}</td>
                    <td class="body-2">{{ __('invoice.payment_term_days', ['net_payment_term' => $invoice->net_payment_term]) }}</td>
                </tr>
            </table>
        </div>
        <div class="invoice-information-column">
            <table class="invoice-information-table">
                @foreach ($invoice->customer?->metadata()->where('display_in_invoice', true)->orderBy('created_at')->get() as $metadata)
                    <tr>
                        <td class="body-1">{{ $metadata->key }}</td>
                        <td class="body-2">{{ $metadata->value }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="mb-24 overflow-auto">
        <div class="billing-information-column">
            <div class="body-1">{{ __('invoice.bill_from') }}</div>
            <div class="body-2">
                @if (filled($ctx->billingEntity()?->legal_name))
                    {{ $ctx->billingEntity()->legal_name }}
                @else
                    {{ $ctx->billingEntity()?->name }}
                @endif
            </div>
            @if (filled($ctx->billingEntity()?->legal_number))
                <div class="body-2">{{ $ctx->billingEntity()->legal_number }}</div>
            @endif
            <div class="body-2">{{ $ctx->billingEntity()?->address_line1 }}</div>
            <div class="body-2">{{ $ctx->billingEntity()?->address_line2 }}</div>
            <div class="body-2">
                <span>{{ $ctx->billingEntity()?->zipcode }}</span>@if (filled($ctx->billingEntity()?->zipcode) && filled($ctx->billingEntity()?->city))<span>, &nbsp;</span>@endif<span>{{ $ctx->billingEntity()?->city }}</span>
            </div>
            @if (filled($ctx->billingEntity()?->state))
                <div class="body-2">{{ $ctx->billingEntity()->state }}</div>
            @endif
            <div class="body-2">{{ $ctx->countryName($ctx->billingEntity()?->country) }}</div>
            <div class="body-2">{{ $ctx->billingEntity()?->email }}</div>
            @if (filled($ctx->billingEntity()?->tax_identification_number))
                <div class="body-2">{{ __('invoice.tax_identification_number', ['tax_identification_number' => $ctx->billingEntity()->tax_identification_number]) }}</div>
            @endif
        </div>
        <div class="billing-information-column">
            <div class="body-1">{{ __('invoice.bill_to') }}</div>
            <div class="body-2">{{ $ctx->customerDisplayName() }}</div>
            @if (filled($invoice->customer?->legal_number))
                <div class="body-2">{{ $invoice->customer->legal_number }}</div>
            @endif
            {{-- Port of v4/_customer_address — see the partial for the
                 Addressing::DefaultFormatter deviation. --}}
            @include('documents.invoices.v4.customer-address')
            <div class="body-2">{{ str_replace(', ', ', ', (string) $invoice->customer?->email) }}</div>
            @if (filled($invoice->customer?->tax_identification_number))
                <div class="body-2">{{ __('invoice.tax_identification_number', ['tax_identification_number' => $invoice->customer->tax_identification_number]) }}</div>
            @endif
        </div>
    </div>

    <div class="mb-24">
        <h2 class="title-2 mb-4">{{ $ctx->money($invoice->total_amount_cents) }}</h2>
        <div class="body-1">{{ __('invoice.due_date', ['date' => $ctx->date($invoice->payment_due_date)]) }}</div>
    </div>

    <div class="invoice-resume mb-24 overflow-auto">
        @if ($ctx->isCredit())
            @include('documents.invoices.v4.credit')
        @elseif ($ctx->isProgressiveBilling())
            {{-- TODO(port): v4/_progressive_billing_details — the applied
                 usage-threshold and progressive-billing credit models are
                 unported; the multi-subscription summary renders instead. --}}
            @include('documents.invoices.v4.subscriptions-summary')
        @elseif ($invoice->subscriptions()->count() === 1)
            @include('documents.invoices.v4.subscription-details')
        @else
            @include('documents.invoices.v4.subscriptions-summary')
        @endif
    </div>

    @include('documents.invoices.v4.eu-tax-management')

    {{-- TODO(port): progressive_billing_last_applied_usage_threshold notice
         and applied_invoice_custom_sections (both models unported). --}}

    <p class="body-3 mb-24">{{ $ctx->billingEntity()?->invoice_footer }}</p>

    @include('documents.invoices.v4.powered-by-logo')

    @if ($invoice->subscriptions()->count() > 1)
        @include('documents.invoices.v4.subscription-details', ['pageBreak' => true])
    @endif
</div>
</body>
</html>
