<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use App\Jobs\Orders\ExecuteOrderJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::ExecuteScheduledOrdersJob
 * (app/jobs/clock/execute_scheduled_orders_job.rb) — the hourly sweep
 * fanning out an Orders::ExecuteOrderJob per order whose execute_at has
 * come due.
 */
class ExecuteScheduledOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
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
        Order::query()
            ->executable()
            ->each(fn (Order $order) => dispatch(new ExecuteOrderJob($order)));
    }
}
