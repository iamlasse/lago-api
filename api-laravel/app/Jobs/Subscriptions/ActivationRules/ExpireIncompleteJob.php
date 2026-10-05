<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Subscriptions\ActivationRules\ExpireService;

/**
 * Port of Rails' Subscriptions::ActivationRules::ExpireIncompleteJob
 * (app/jobs/subscriptions/activation_rules/expire_incomplete_job.rb).
 */
class ExpireIncompleteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly Subscription $subscription,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function handle(): void
    {
        ExpireService::callBang(
            subscription: Subscription::query()->find($this->subscription->id) ?? $this->subscription,
        );
    }
}
