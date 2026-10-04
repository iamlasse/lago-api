<?php

declare(strict_types=1);

namespace App\Jobs\PaymentReceipts;

use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceiptCreatedMail;

/**
 * Port of Rails' PaymentReceipts::NotifyJob
 * (app/jobs/payment_receipts/notify_job.rb) — deliver the receipt email
 * (PaymentReceiptMailer.with(payment_receipt:).created).
 *
 * Rails queues the mailer itself through SendEmailJob; the port sends
 * synchronously inside this queued job (same as the invoices NotifyJob).
 */
class NotifyJob extends DocumentsJob
{
    public function __construct(public readonly PaymentReceipt $paymentReceipt)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $mailable = new PaymentReceiptCreatedMail($this->paymentReceipt);

        if (! $mailable->shouldSend()) {
            return;
        }

        Mail::to($mailable->recipients())->send($mailable);
    }
}
