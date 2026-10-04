{{-- Minimal port of Rails' app/views/payment_receipt_mailer/created.slim —
     the headline receipt fields (TODO(port): the payment-request variant's
     remaining-to-pay block and the full metadata table). --}}
@extends('emails.layout')

@section('content')
    <table cellpadding="0" cellspacing="0" style="margin: auto; padding-bottom: 24px">
        <tr>
            <td style="color: #66758f; font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: center;">
                {{ __('email.payment_receipt.created.subject', [
                    'billing_entity_name' => $billingEntity?->name,
                    'payment_receipt_number' => $paymentReceipt->number,
                ]) }}
            </td>
        </tr>
        <tr>
            <td style="color: #19212e; font-size: 32px; font-weight: 700; line-height: 40px; letter-spacing: 0em; text-align: center;">
                {{ $paymentReceipt->number }}
            </td>
        </tr>
    </table>

    <table cellpadding="0" cellspacing="0" style="width: 100%; padding: 24px 0; border-top: 1px solid #d9dee7; border-bottom: 1px solid #d9dee7;">
        <tr>
            <td>
                <table cellpadding="0" cellspacing="0" style="width: 100%">
                    <tr>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: left; padding-right: 16px; color: #66758f; white-space: nowrap; padding-bottom: 4px;">
                            {{ __('payment_receipt.number') }}
                        </td>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: right; color: #19212e; white-space: nowrap; padding-bottom: 4px;">
                            {{ $paymentReceipt->number }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: left; padding-right: 16px; color: #66758f; white-space: nowrap;">
                            {{ __('payment_receipt.payment_date') }}
                        </td>
                        <td style="font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: right; color: #19212e; white-space: nowrap;">
                            {{ $paymentReceipt->created_at?->isoFormat('LL') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if (! empty($showLagoLogo))
        <table cellpadding="0" cellspacing="0" style="width: 100%; padding: 24px 0;">
            <tr>
                <td style="text-align: center;">
                    <img src="{{ $lagoLogoUrl }}" alt="Lago" style="height: 32px;">
                </td>
            </tr>
        </table>
    @endif
@endsection
