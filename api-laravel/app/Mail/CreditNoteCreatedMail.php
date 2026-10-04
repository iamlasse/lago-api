<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CreditNote;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Stub port of Rails' CreditNoteMailer (app/mailers/credit_note_mailer.rb).
 *
 * TODO(port): the credit-note PDF generation (CreditNotes::GeneratePdfService)
 * is a later milestone, so the mailer's ensure_pdf step and the
 * credit_note-{number}.pdf attachment are not wired yet — until the credit
 * notes document pipeline lands nothing enqueues this mailable.
 */
class CreditNoteCreatedMail extends Mailable
{
    public function __construct(public readonly CreditNote $creditNote) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('email.credit_note.created.subject', [
                'billing_entity_name' => $this->creditNote->billingEntity?->name,
                'credit_note_number' => $this->creditNote->number,
            ]),
        );
    }

    public function content(): Content
    {
        // TODO(port): credit note PDF attachment + billing entity reply-to
        // mirror of InvoiceCreatedMail once CreditNotes::GeneratePdfService
        // is ported.
        return new Content(
            view: 'emails.credit_note.created',
            with: ['creditNote' => $this->creditNote],
        );
    }
}
