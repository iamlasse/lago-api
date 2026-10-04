{{-- Port of Rails' app/views/invoice_mailer/created.slim. --}}
@php($ctx = new App\Support\Documents\InvoicePdf($invoice))
@extends('emails.layout')

@section('content')
    <table cellpadding="0" cellspacing="0" style="margin: auto; padding-bottom: 24px">
        <tr>
            <td style="color: #66758f; font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: center;">
                {{ __('email.invoice.finalized.invoice_from', ['billing_entity_name' => $billingEntity?->name]) }}
            </td>
        </tr>
        <tr>
            <td style="color: #19212e; font-size: 32px; font-weight: 700; line-height: 40px; letter-spacing: 0em; text-align: center;">
                {{ $ctx->money($invoice->total_amount_cents) }}
            </td>
        </tr>
        <tr>
            <td style="color: #66758f; font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: center;">
                {{ __('email.invoice.finalized.issued_on', ['date' => $ctx->date($invoice->issuing_date)]) }}
            </td>
        </tr>
    </table>
    <table cellpadding="0" cellspacing="0" style="width: 100%; padding: 24px 0; border-top: 1px solid #d9dee7; border-bottom: 1px solid #d9dee7;">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0" style="width: 100%">
                    <tr>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: left; padding-right: 16px; color: #66758f; white-space: nowrap; padding-bottom: 4px;">
                            {{ __('email.invoice.finalized.invoice_number') }}
                        </td>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: right; color: #19212e; white-space: nowrap; padding-bottom: 4px;">
                            {{ $invoice->number }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: left; padding-right: 16px; color: #66758f; white-space: nowrap;">
                            {{ __('email.invoice.finalized.issue_date') }}
                        </td>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: right; color: #19212e; white-space: nowrap;">
                            {{ $ctx->date($invoice->issuing_date) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($showLagoLogo) && $invoice->fileUrl() !== null)
        <table cellpadding="0" cellspacing="0" style="width: 100%; padding: 24px 0; border-bottom: 1px solid #d9dee7;">
            <tr>
                <td>
                    <table cellpadding="0" cellspacing="0" style="margin: auto">
                        <tr>
                            <td style="padding-right: 8px;">
                                <svg height="16px" width="16px" fill="#006CFA" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" style="padding-top: 4px">
                                    <path d="M12.705 10.27C12.5176 10.0838 12.2642 9.97921 12 9.97921C11.7358 9.97921 11.4824 10.0838 11.295 10.27L8.99999 12.75V1C8.99999 0.734784 8.89463 0.48043 8.7071 0.292893C8.51956 0.105357 8.26521 0 7.99999 0C7.73477 0 7.48042 0.105357 7.29288 0.292893C7.10535 0.48043 6.99999 0.734784 6.99999 1V12.755L4.70499 10.255C4.51763 10.0688 4.26417 9.96421 3.99999 9.96421C3.7358 9.96421 3.48235 10.0688 3.29499 10.255C3.19898 10.3482 3.12265 10.4597 3.07053 10.583C3.0184 10.7062 2.99155 10.8387 2.99155 10.9725C2.99155 11.1063 3.0184 11.2388 3.07053 11.362C3.12265 11.4853 3.19898 11.5968 3.29499 11.69L6.93999 15.54C7.22124 15.8209 7.60249 15.9787 7.99999 15.9787C8.39749 15.9787 8.77874 15.8209 9.05999 15.54L12.705 11.69C12.7987 11.597 12.8731 11.4864 12.9239 11.3646C12.9746 11.2427 13.0008 11.112 13.0008 10.98C13.0008 10.848 12.9746 10.7173 12.9239 10.5954C12.8731 10.4736 12.7987 10.363 12.705 10.27Z"/>
                                </svg>
                            </td>
                            <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; color: #19212e;">
                                <a style="text-decoration: none" href="{{ $invoice->fileUrl() }}">{{ __('email.invoice.finalized.download') }}</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @endif
@endsection
