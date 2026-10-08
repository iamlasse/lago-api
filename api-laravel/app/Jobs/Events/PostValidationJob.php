<?php

declare(strict_types=1);

namespace App\Jobs\Events;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Events\PostValidationService;

/**
 * Port of Rails' Events::PostValidationJob (app/jobs/events/post_validation_job.rb)
 * — validates the last hour of ingested events for one organization;
 * events queue when SIDEKIQ_EVENTS is set, like every queue-split job.
 */
class PostValidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly Organization $organization)
    {
        $this->onQueue(filter_var(env('SIDEKIQ_EVENTS'), FILTER_VALIDATE_BOOL) ? 'events' : 'default');
    }

    public function handle(): void
    {
        PostValidationService::call(organization: $this->organization);
    }
}
