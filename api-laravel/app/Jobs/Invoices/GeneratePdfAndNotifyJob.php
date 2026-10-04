<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;

/**
 * Port of Rails' Invoices::GeneratePdfAndNotifyJob
 * (app/jobs/invoices/generate_pdf_and_notify_job.rb) — a thin fan-out to
 * GenerateDocumentsJob with the notify flag.
 */
class GeneratePdfAndNotifyJob extends DocumentsJob
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $email,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        GenerateDocumentsJob::dispatch($this->invoice, $this->email);
    }
}
