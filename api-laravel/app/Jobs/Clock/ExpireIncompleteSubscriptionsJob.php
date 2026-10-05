<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Subscription;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Jobs\Subscriptions\ActivationRules\ExpireIncompleteJob;

/**
 * Port of Rails' Clock::ExpireIncompleteSubscriptionsJob
 * (app/jobs/clock/expire_incomplete_subscriptions_job.rb) — expires every
 * incomplete subscription whose pending activation rule timed out.
 *
 * clock.rb: every(1.hour, "schedule:expire_incomplete_subscriptions", at: "*:20").
 */
class ExpireIncompleteSubscriptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function handle(): void
    {
        // Rails: Subscription.expirable — incomplete subscriptions with an
        // expired pending activation rule.
        Subscription::query()
            ->where('subscriptions.status', 4) // incomplete
            ->whereHas('activationRules', fn ($q) => $q
                ->where('subscription_activation_rules.status', 'pending')
                ->where('subscription_activation_rules.expires_at', '<=', now()))
            ->each(fn (Subscription $subscription) => ExpireIncompleteJob::dispatch($subscription));
    }
}
