<?php

declare(strict_types=1);

namespace App\Jobs\LifetimeUsages;

use App\Models\LifetimeUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\LifetimeUsages\CalculateService;
use App\Services\LifetimeUsages\CheckThresholdsService;

/**
 * Port of Rails' LifetimeUsages::RecalculateAndCheckJob
 * (app/jobs/lifetime_usages/recalculate_and_check_job.rb).
 *
 * NOTE (Rails): this job can run concurrently for the same lifetime usage
 * (the clock's async sweep and the inline perform_now from subscription
 * activity processing don't share the uniqueness lock). When they race on
 * the same passed threshold, the losing run raises an IdempotencyError
 * because the progressive billing invoice was already created by the winning
 * run — a benign no-op.
 *
 * TODO(port): the Idempotency guard of Invoices::ProgressiveBillingService
 * is not ported yet, so the concurrent-run discard cannot be reproduced; the
 * job serializes on the invoice pipeline's locks instead.
 */
class RecalculateAndCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly LifetimeUsage|string $lifetimeUsage,
        public readonly mixed $currentUsage = null,
    ) {
        $this->onQueue('default');
    }

    /** Rails: `lock_key_arguments [arguments.first]` + lock_ttl: 12.hours. */
    public function uniqueFor(): int
    {
        return 12 * 3600;
    }

    public function uniqueKey(): string
    {
        $id = $this->lifetimeUsage instanceof LifetimeUsage ? $this->lifetimeUsage->id : $this->lifetimeUsage;

        return static::class.':'.$id;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        $lifetimeUsage = $this->lifetimeUsage instanceof LifetimeUsage
            ? $this->lifetimeUsage
            : LifetimeUsage::query()->find($this->lifetimeUsage);

        if ($lifetimeUsage === null) {
            return;
        }

        // NOTE: do not pass current usage through the queue as it will be huge.
        CalculateService::callBang(lifetimeUsage: $lifetimeUsage, currentUsage: $this->currentUsage);

        if ($lifetimeUsage->organization->progressiveBillingEnabled()) {
            CheckThresholdsService::callBang(lifetimeUsage: $lifetimeUsage);
        }
    }
}
