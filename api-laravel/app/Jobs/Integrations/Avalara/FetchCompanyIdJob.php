<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Avalara;

use App\Models\Integration;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Avalara\FetchCompanyIdService;

/**
 * Port of Rails' Integrations::Avalara::FetchCompanyIdJob
 * (app/jobs/integrations/avalara/fetch_company_id_job.rb).
 *
 * TODO(port): retry_on (HttpError 3 attempts, ThrottlingError 25) — the
 * worker's $tries covers it until the queue hardening slice.
 */
class FetchCompanyIdJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Integration $integration)
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        FetchCompanyIdService::callBang(integration: $this->integration);
    }
}
