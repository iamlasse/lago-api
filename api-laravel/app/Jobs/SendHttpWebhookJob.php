<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Webhooks\SendHttpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' SendHttpWebhookJob (app/jobs/send_http_webhook_job.rb) —
 * one HTTP delivery of one webhook row (SendHttpService owns the retry
 * policy; a failed attempt re-enqueues this job with a backoff delay until
 * LAGO_WEBHOOK_ATTEMPTS is exhausted).
 *
 * Rails' `retry_on ActiveJob::DeserializationError` (3 attempts, then discard
 * with a warning) maps to the worker's retry behaviour on
 * ModelNotFoundException; the discard logging is the worker's `failed` hook.
 */
class SendHttpWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Webhook $webhook,
    ) {
        $this->onQueue(static::queueFor($this->webhook->webhook_type));
    }

    public static function queueFor(?string $webhookType = null): string
    {
        return SendWebhookJob::queueFor($webhookType);
    }

    public function handle(): void
    {
        SendHttpService::call(webhook: $this->webhook);
    }
}
