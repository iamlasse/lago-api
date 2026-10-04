<?php

declare(strict_types=1);

namespace App\Jobs\PaymentReceipts;

use App\Models\PaymentReceipt;
use App\Services\PaymentReceipts\GeneratePdfService;
use App\Services\PaymentReceipts\GenerateXmlService;

/**
 * Port of Rails' PaymentReceipts::GenerateDocumentsJob
 * (app/jobs/payment_receipts/generate_documents_job.rb) — generate the
 * receipt's XML then PDF documents, optionally notifying the customer
 * afterwards.
 */
class GenerateDocumentsJob extends DocumentsJob
{
    public function __construct(
        public readonly PaymentReceipt $paymentReceipt,
        public readonly bool $notify = false,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        GenerateXmlService::callBang(paymentReceipt: $this->paymentReceipt);

        GeneratePdfService::callBang(paymentReceipt: $this->paymentReceipt);

        if ($this->notify) {
            NotifyJob::dispatch($this->paymentReceipt);
        }
    }
}
