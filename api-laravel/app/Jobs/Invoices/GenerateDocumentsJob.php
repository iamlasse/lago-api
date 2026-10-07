<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use App\Services\Invoices\GeneratePdfService;
use App\Services\Invoices\GenerateXmlService;

/**
 * Port of Rails' Invoices::GenerateDocumentsJob
 * (app/jobs/invoices/generate_documents_job.rb) — generate the invoice's
 * XML then PDF documents, optionally notifying the customer afterwards.
 */
class GenerateDocumentsJob extends DocumentsJob
{
    public int $tries = 6;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $notify = false,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        GenerateXmlService::callBang(invoice: $this->invoice);

        GeneratePdfService::callBang(invoice: $this->invoice);

        if ($this->notify) {
            dispatch(new \App\Jobs\Invoices\NotifyJob($this->invoice));
        }
    }
}
