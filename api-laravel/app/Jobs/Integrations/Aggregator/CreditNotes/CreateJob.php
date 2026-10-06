<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Aggregator\CreditNotes;

use App\Models\CreditNote;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Aggregator\CreditNotes\CreateService;

/**
 * Port of Rails' Integrations::Aggregator::CreditNotes::CreateJob
 * (app/jobs/integrations/aggregator/credit_notes/create_job.rb).
 *
 * TODO(port): `unique :until_executed`, the ConcurrencyThrottlable concern
 * and the retry_on table (HttpError 3 attempts polynomial, RequestLimitError
 * 100, ThrottlingError 25) — same queue-hardening debt as the invoices job.
 */
class CreateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CreditNote $credit_note,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        CreateService::callBang(credit_note: $this->credit_note);
    }
}
