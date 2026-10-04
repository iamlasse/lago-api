<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use App\Services\Invoices\GeneratePdfService;

/**
 * Port of Rails' Invoices::GeneratePdfJob
 * (app/jobs/invoices/generate_pdf_job.rb) — the download endpoint's
 * regenerate-then-serve job: render the PDF through Gotenberg with the
 * "api" context and raise on failure (driving the retries).
 */
class GeneratePdfJob extends DocumentsJob
{
    public int $tries = 6;

    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        GeneratePdfService::call(invoice: $this->invoice, context: 'api')->raiseIfError();
    }
}
