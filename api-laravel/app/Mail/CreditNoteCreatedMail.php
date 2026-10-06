<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\CreditNote;
use App\Support\License;
use Illuminate\Mail\Mailable;
use App\Support\ActiveStorage;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Port of Rails' CreditNoteMailer#created (app/mailers/credit_note_mailer.rb
 * + app/views/credit_note_mailer/created.slim) — the credit note email with
 * the PDF attached, sent after credit_note.created.
 *
 * The create_mail guards (billing entity reply-to email blank, no
 * recipients) are exposed through shouldSend() — callers check it before
 * handing the mailable to the Mail facade, the Laravel equivalent of Rails'
 * mailer returning nil (no delivery).
 */
class CreditNoteCreatedMail extends Mailable
{
    public function __construct(
        public readonly CreditNote $creditNote,
        public readonly bool $resend = false,
        public readonly ?array $recipientTo = null,
        public readonly ?array $recipientCc = null,
        public readonly ?array $recipientBcc = null,
    ) {}

    /** Rails: @pdfs_enabled (ApplicationMailer#set_shared_variables). */
    public static function pdfsEnabled(): bool
    {
        return ! config('lago.disable_pdf_generation');
    }

    /** Rails: recipients = params[:to].presence || [@customer.email]. */
    public function recipients(): array
    {
        if ($this->recipientTo !== null && $this->recipientTo !== []) {
            return array_values($this->recipientTo);
        }

        return array_values(array_filter([(string) $this->creditNote->customer?->email]));
    }

    /**
     * Rails create_mail early returns: billing_entity.email.blank?,
     * recipients.empty? (the zero-amount rule is invoice-only).
     */
    public function shouldSend(): bool
    {
        $billingEntity = $this->creditNote->billingEntity;

        if ($billingEntity === null || ($billingEntity->email ?? '') === '') {
            return false;
        }

        return $this->recipients() !== [];
    }

    /** Rails: billing_entity.from_email_address (see BillingEntity::fromEmailAddress). */
    public function fromEmailAddress(): ?string
    {
        return $this->creditNote->billingEntity?->fromEmailAddress();
    }

    public function envelope(): Envelope
    {
        $billingEntity = $this->creditNote->billingEntity;

        $envelope = new Envelope(
            subject: __('email.credit_note.created.subject', [
                'billing_entity_name' => $billingEntity?->name,
                'credit_note_number' => $this->creditNote->number,
            ]),
        );

        $from = $this->fromEmailAddress();

        if ($from !== null && $from !== '') {
            $envelope->from($from, $billingEntity?->name);
        }

        if (($billingEntity?->email ?? '') !== '') {
            $envelope->replyTo($billingEntity->email, $billingEntity->name);
        }

        if ($this->recipientCc !== null && $this->recipientCc !== []) {
            $envelope->cc($this->recipientCc);
        }

        if ($this->recipientBcc !== null && $this->recipientBcc !== []) {
            $envelope->bcc($this->recipientBcc);
        }

        return $envelope;
    }

    public function content(): Content
    {
        $this->attachPdf();

        return new Content(
            view: 'emails.credit_note.created',
            with: [
                'creditNote' => $this->creditNote,
                'billingEntity' => $this->creditNote->billingEntity,
                // Rails: @show_lago_logo = !organization.remove_branding_watermark_enabled?
                // (a premium integration flag).
                'showLagoLogo' => ! (License::premium()
                    && in_array('remove_branding_watermark', (array) ($this->creditNote->organization?->premium_integrations ?? []), true)),
                'lagoLogoUrl' => 'https://assets.getlago.com/lago-logo-email.png',
            ],
        );
    }

    /** Rails: document.file.open { |file| attachments[...] = file.read }. */
    private function attachPdf(): void
    {
        if (! static::pdfsEnabled()) {
            return;
        }

        $blob = ActiveStorage::blob($this->creditNote, ActiveStorage::FILE);

        if ($blob === null) {
            // Rails' ensure_pdf before_action generates the file first; an
            // absent attachment means generation failed upstream — deliver
            // without the attachment rather than failing the send.
            return;
        }

        $this->attachData(
            ActiveStorage::download($blob),
            'credit_note-'.$this->creditNote->number.'.pdf',
            ['mime' => 'application/pdf'],
        );
    }
}
