{{-- Minimal stub view for the CreditNoteCreatedMail stub — the full credit
     note mailer port lands with the credit-notes document pipeline. --}}
@extends('emails.layout')

@section('content')
    <table cellpadding="0" cellspacing="0" style="margin: auto; padding-bottom: 24px">
        <tr>
            <td style="color: #66758f; font-size: 14px; font-weight: 400; line-height: 20px; letter-spacing: 0em; text-align: center;">
                {{ __('email.credit_note.created.subject', [
                    'billing_entity_name' => $creditNote->billingEntity?->name,
                    'credit_note_number' => $creditNote->number,
                ]) }}
            </td>
        </tr>
    </table>
@endsection
