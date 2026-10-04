<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
use App\Support\License;
use Illuminate\Mail\Mailable;
use App\Support\ActiveStorage;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Port of Rails' InvoiceMailer#created (app/mailers/invoice_mailer.rb +
 * app/views/invoice_mailer/created.slim) — the invoice email with the PDF
 * attached, sent after invoice.finalized.
 *
 * The create_mail guards (billing entity reply-to email blank, no
 * recipients, zero-fee invoices) are exposed through shouldSend() — the
 * NotifyJob checks it before handing the mailable to the Mail facade, the
 * Laravel equivalent of Rails' mailer returning nil (no delivery).
 */
class InvoiceCreatedMail extends Mailable
{
    public function __construct(public readonly Invoice $invoice) {}

    /** Rails: @pdfs_enabled (ApplicationMailer#set_shared_variables). */
    public static function pdfsEnabled(): bool
    {
        return ! config('lago.disable_pdf_generation');
    }

    /** Rails: recipients = params[:to].presence || [@customer.email]. */
    public function recipients(): array
    {
        return array_values(array_filter([(string) $this->invoice->customer?->email]));
    }

    /**
     * Rails create_mail early returns: billing_entity.email.blank?,
     * recipients.empty?, document.fees_amount_cents.zero?.
     */
    public function shouldSend(): bool
    {
        $billingEntity = $this->invoice->billingEntity;

        if ($billingEntity === null || ($billingEntity->email ?? '') === '') {
            return false;
        }

        if ($this->recipients() === []) {
            return false;
        }

        return (int) $this->invoice->fees_amount_cents !== 0;
    }

    /** Rails: billing_entity.from_email_address (see BillingEntity::fromEmailAddress). */
    public function fromEmailAddress(): ?string
    {
        return $this->invoice->billingEntity?->fromEmailAddress();
    }

    public function envelope(): Envelope
    {
        $billingEntity = $this->invoice->billingEntity;

        $envelope = new Envelope(
            subject: __('email.invoice.finalized.subject', [
                'billing_entity_name' => $billingEntity?->name,
                'invoice_number' => $this->invoice->number,
            ]),
        );

        $from = $this->fromEmailAddress();

        if ($from !== null && $from !== '') {
            $envelope->from($from, $billingEntity?->name);
        }

        if (($billingEntity?->email ?? '') !== '') {
            $envelope->replyTo($billingEntity->email, $billingEntity->name);
        }

        return $envelope;
    }

    public function content(): Content
    {
        $this->attachPdf();

        return new Content(
            view: 'emails.invoice.created',
            with: [
                'invoice' => $this->invoice,
                'billingEntity' => $this->invoice->billingEntity,
                // Rails: @show_lago_logo = !organization.remove_branding_watermark_enabled?
                // (a premium integration flag).
                'showLagoLogo' => ! (License::premium()
                    && in_array('remove_branding_watermark', (array) ($this->invoice->organization?->premium_integrations ?? []), true)),
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

        $blob = ActiveStorage::blob($this->invoice, ActiveStorage::FILE);

        if ($blob === null) {
            // Rails' ensure_pdf before_action generates the file first; in
            // the port NotifyJob runs after GenerateDocumentsJob, so an
            // absent attachment means generation failed upstream — deliver
            // without the attachment rather than failing the send.
            return;
        }

        $this->attachData(
            ActiveStorage::download($blob),
            'invoice-'.$this->invoice->number.'.pdf',
            ['mime' => 'application/pdf'],
        );
    }
}
