<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Customer;
use App\Jobs\Orders\ExecuteOrderJob;
use Database\Factories\OrderFactory;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\ExecuteScheduledOrdersJob;

uses()->group('ledger:job:Clock.ExecuteScheduledOrdersJob');

/**
 * Port of Rails' spec/jobs/clock/execute_scheduled_orders_job_spec.rb — the
 * hourly sweep enqueues an Orders::ExecuteOrderJob only for created orders
 * whose execute_at has come due.
 */
function clockOrder(array $attributes = []): Order
{
    /** @var OrderFactory $factory */
    $factory = Order::factory();

    return $factory
        ->forCustomer(Customer::factory()->create())
        ->state(fn (): array => $attributes)
        ->create();
}

it('enqueues execute jobs only for due, created orders', function (): void {
    Queue::fake();

    $dueOrder = clockOrder(['execute_at' => now()->subHour()]);
    $futureOrder = clockOrder(['execute_at' => now()->addHour()]);
    $executedOrder = clockOrder(['status' => 'executed', 'execute_at' => now()->subHour()]);
    $failedOrder = clockOrder(['status' => 'failed', 'execute_at' => now()->subHour()]);

    (new ExecuteScheduledOrdersJob)->handle();

    Queue::assertPushed(ExecuteOrderJob::class, 1);

    Queue::assertPushed(
        ExecuteOrderJob::class,
        fn (ExecuteOrderJob $job) => $job->order->id === $dueOrder->id,
    );
});
