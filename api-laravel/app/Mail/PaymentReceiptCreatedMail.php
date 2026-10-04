<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
use App\Support\License;
use Illuminate\Mail\Mailable;
use App\Models\PaymentReceipt;
use App\Support\ActiveStorage;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Stub port of Rails' PaymentReceiptMailer (app/mailers/payment_receipt_mailer.rb).
 *
 * The ensure_pdf before_action (regenerate the receipt PDF, require it and
 * the payable invoice PDFs) is mirrored through shouldSend() — a missing
 * attachment answers false instead of raising FilesNotReadyError, matching
 * the "minimal mail body" scope of the slice.
 *
 * TODO(port): the invoice PDFs attachment loop (Rails attaches
 * receipt-{number}.pdf plus invoice-{number}.pdf for every payable invoice)
 * and the payment-request variant's remaining-to-pay rendering.
 */
class PaymentReceiptCreatedMail extends Mailable
{
    public function __construct(
        public readonly PaymentReceipt $paymentReceipt,
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

    /** Rails: recipients = params[:to].presence || [@customer.email].compact_blank. */
    public function recipients(): array
    {
        if ($this->recipientTo !== null && $this->recipientTo !== []) {
            return array_values($this->recipientTo);
        }

        return array_values(array_filter([(string) $this->paymentReceipt->customer?->email]));
    }

    /**
     * Rails create_mail early returns (billing entity email blank, no
     * recipients) + the ensure_pdf guard: the receipt PDF and every payable
     * invoice PDF must be attached (GeneratePdfService regenerated it —
     * FilesNotReadyError otherwise).
     */
    public function shouldSend(): bool
    {
        $billingEntity = $this->paymentReceipt->billingEntity;

        if ($billingEntity === null || ($billingEntity->email ?? '') === '') {
            return false;
        }

        if ($this->recipients() === []) {
            return false;
        }

        if (! $this->paymentReceipt->hasFile()) {
            return false;
        }

        foreach ($this->payableInvoices() as $invoice) {
            if (! $invoice->hasFile()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Invoice> */
    public function payableInvoices(): array
    {
        $payable = $this->paymentReceipt->payment?->payable;

        if ($payable instanceof Invoice) {
            return [$payable];
        }

        return $payable?->invoices instanceof \Illuminate\Support\Collection
            ? $payable->invoices->all()
            : [];
    }

    public function envelope(): Envelope
    {
        $billingEntity = $this->paymentReceipt->billingEntity;

        $envelope = new Envelope(
            subject: __('email.payment_receipt.created.subject', [
                'billing_entity_name' => $billingEntity?->name,
                'payment_receipt_number' => $this->paymentReceipt->number,
            ]),
        );

        $from = $billingEntity?->fromEmailAddress();

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
        $this->attachPdfs();

        return new Content(
            view: 'emails.payment_receipt.created',
            with: [
                'paymentReceipt' => $this->paymentReceipt,
                'billingEntity' => $this->paymentReceipt->billingEntity,
                'showLagoLogo' => ! (License::premium()
                    && in_array('remove_branding_watermark', (array) ($this->paymentReceipt->organization?->premium_integrations ?? []), true)),
                'lagoLogoUrl' => 'https://assets.getlago.com/lago-logo-email.png',
            ],
        );
    }

    /** Rails: receipt-{number}.pdf (+ TODO(port): the payable invoices' PDFs). */
    private function attachPdfs(): void
    {
        if (! static::pdfsEnabled()) {
            return;
        }

        $blob = ActiveStorage::blob($this->paymentReceipt, ActiveStorage::FILE);

        if ($blob === null) {
            return;
        }

        $this->attachData(
            ActiveStorage::download($blob),
            'receipt-'.$this->paymentReceipt->number.'.pdf',
            ['mime' => 'application/pdf'],
        );
    }
}
