<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Aggregator;

use App\Models\Integration;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Aggregator\SendRestletEndpointService;

/**
 * Port of Rails' Integrations::Aggregator::SendRestletEndpointJob
 * (app/jobs/integrations/aggregator/send_restlet_endpoint_job.rb).
 *
 * TODO(port): retry_on (HttpError 3 attempts, RequestLimitError 100) — the
 * worker's $tries covers it until the queue hardening slice.
 */
class SendRestletEndpointJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Integration $integration)
    {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        SendRestletEndpointService::callBang(integration: $this->integration);
    }
}
