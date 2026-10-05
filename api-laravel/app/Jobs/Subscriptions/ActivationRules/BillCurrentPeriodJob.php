<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions\ActivationRules;

use App\Models\Subscription;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Subscriptions\ActivationRules\BillCurrentPeriodService;

/**
 * Port of Rails' Subscriptions::ActivationRules::BillCurrentPeriodJob
 * (app/jobs/subscriptions/activation_rules/bill_current_period_job.rb).
 *
 * Rails' retry_on stanzas (Sequenced::SequenceError, ThrottlingError, lock
 * failures) map onto the worker's retry configuration; the unique-until-
 * executed lock is kept.
 */
class BillCurrentPeriodJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $maxExceptions = 25;

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
        BillCurrentPeriodService::callBang(
            subscription: Subscription::query()->find($this->subscription->id) ?? $this->subscription,
        );
    }
}
