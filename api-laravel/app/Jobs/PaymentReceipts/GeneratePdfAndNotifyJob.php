<?php

declare(strict_types=1);

namespace App\Jobs\PaymentReceipts;

use App\Models\PaymentReceipt;

/**
 * Port of Rails' PaymentReceipts::GeneratePdfAndNotifyJob
 * (app/jobs/payment_receipts/generate_pdf_and_notify_job.rb) — a thin alias
 * enqueuing the documents job (optionally with the customer email).
 */
class GeneratePdfAndNotifyJob extends DocumentsJob
{
    public function __construct(
        public readonly PaymentReceipt $paymentReceipt,
        public readonly bool $email = false,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        dispatch(new \App\Jobs\PaymentReceipts\GenerateDocumentsJob(paymentReceipt: $this->paymentReceipt, notify: $this->email));
    }
}
