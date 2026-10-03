<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use Throwable;
use App\Models\Event;
use Illuminate\Queue\SerializesModels;
use App\Services\Failures\FailedResult;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Events\PostProcessService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Port of Rails' Events::PostProcessJob (app/jobs/events/post_process_job.rb):
 * runs the post-ingestion processing on the `events` queue when
 * SIDEKIQ_EVENTS is set, like every queue-split job.
 */
class PostProcessJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Event $event)
    {
        $this->onQueue(filter_var(env('SIDEKIQ_EVENTS'), FILTER_VALIDATE_BOOL) ? 'events' : 'default');
    }

    /**
     * @throws Throwable
     * @throws FailedResult
     */
    public function handle(): void
    {
        PostProcessService::call(event: $this->event)->raiseIfError();
    }
}
