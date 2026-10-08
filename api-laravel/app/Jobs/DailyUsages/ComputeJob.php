<?php

declare(strict_types=1);

namespace App\Jobs\DailyUsages;

use Throwable;
use PDOException;
use Carbon\CarbonInterface;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DailyUsages\ComputeService;
use Illuminate\Queue\Middleware\ThrottlesExceptions;

/**
 * Port of Rails' DailyUsages::ComputeJob (app/jobs/daily_usages/compute_job.rb)
 * — computes one subscription's daily usage snapshot; analytics queue when
 * SIDEKIQ_ANALYTICS is set, like every queue-split job.
 *
 * Rails: `retry_on ActiveRecord::ActiveRecordError, wait:
 * :polynomially_longer, attempts: 6` — the port throttles retries on
 * database exceptions the same way (5 attempts + the initial one).
 *
 * Rails: `unique :until_and_while_executing, lock_ttl: 3.hours,
 * runtime_lock_ttl: 30.minutes`, keyed on
 * `[subscription.id, timestamp_in_customer_tz.to_date]` (lock_key_arguments).
 */
class ComputeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly CarbonInterface $timestamp,
    ) {
        $this->onQueue(
            filter_var(env('SIDEKIQ_ANALYTICS'), FILTER_VALIDATE_BOOL) ? 'analytics' : 'low_priority'
        );
    }

    /** Rails: lock_ttl — 3.hours. */
    public function uniqueFor(): int
    {
        return 3 * 3600;
    }

    /** Rails: lock_key_arguments — [subscription.id, customer-timezone date]. */
    public function uniqueKey(): string
    {
        $timestampInCustomerTz = $this->timestamp->copy()
            ->setTimezone($this->subscription->customer->applicableTimezone());

        return $this->subscription->id.'|'.$timestampInCustomerTz->toDateString();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // retry_on ActiveRecord::ActiveRecordError, attempts: 6 — only
        // database errors are retried (throttled), anything else fails the
        // job immediately, like Rails.
        return [
            new UniqueJob,
            (new ThrottlesExceptions(5, 10))
                ->byException(fn (Throwable $e): bool => $e instanceof QueryException
                    || $e instanceof PDOException),
        ];
    }

    /**
     * Rails: `wait: :polynomially_longer` — attempt N waits `N^4 + 2`
     * seconds: 3, 18, 83, 258, 627 (then the job fails on attempt 6).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return array_map(
            fn (int $attempt): int => $attempt ** 4 + 2,
            range(1, 5)
        );
    }

    public function handle(): void
    {
        ComputeService::callBang(
            subscription: $this->subscription,
            timestamp: $this->timestamp,
        );
    }
}
