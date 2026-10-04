<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use App\Mail\InvoiceCreatedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Port of Rails' Invoices::NotifyJob (app/jobs/invoices/notify_job.rb) —
 * deliver the invoice email (InvoiceMailer.with(invoice:).created).
 *
 * Rails queues the mailer itself through SendEmailJob; the port sends
 * synchronously inside this queued job, which lands the email on the same
 * :pdfs/:invoices worker semantics without a second queue hop.
 */
class NotifyJob extends DocumentsJob
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $mailable = new InvoiceCreatedMail($this->invoice);

        if (! $mailable->shouldSend()) {
            return;
        }

        // TODO(port): the explicit to/cc/bcc params of Rails' mailers (the
        // Emails::ResendService slice) — the notified mailer always sends to
        // the customer's email.
        Mail::to($mailable->recipients())->send($mailable);
    }
}
