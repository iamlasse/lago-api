<?php

declare(strict_types=1);

namespace App\Jobs\Orders;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use App\Services\Orders\ExecuteService;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Orders::ExecuteOrderJob (app/jobs/orders/execute_order_job.rb)
 * — executes a scheduled order (from Clock::ExecuteScheduledOrdersJob or
 * the executeOrder mutation).
 *
 * Rails retries on BaseLockService::FailedToAcquireLock (10 attempts,
 * random 1-5s wait); the port lets a lock failure bubble to the queue
 * retry handling instead, matching the "next sweep picks it up" character
 * of the clock fan-out.
 */
class ExecuteOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly Order $order,
    ) {
        $this->onQueue('default');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 20 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        ExecuteService::callBang(order: $this->order);
    }
}
