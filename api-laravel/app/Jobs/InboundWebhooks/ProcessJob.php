<?php

declare(strict_types=1);

namespace App\Jobs\InboundWebhooks;

use Illuminate\Bus\Queueable;
use App\Models\InboundWebhook;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\InboundWebhooks\ProcessService;

/**
 * Port of Rails' InboundWebhooks::ProcessJob
 * (app/jobs/inbound_webhooks/process_job.rb) — the async processing half:
 * InboundWebhooks::CreateService persists the verified payload and enqueues
 * this job; the retry clock (Clock::InboundWebhooksRetryJob) re-feeds the
 * same job for rows that fell out of the processing window.
 *
 * callBang: a handler failure raises, so the queue retries per the
 * connection's $tries — while the service has already marked the webhook
 * failed (its own lifecycle state, retried only by the clock).
 */
class ProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly InboundWebhook $inboundWebhook)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        ProcessService::callBang(inboundWebhook: $this->inboundWebhook);
    }
}
