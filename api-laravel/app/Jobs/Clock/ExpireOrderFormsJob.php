<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\OrderForm;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Jobs\OrderForms\ExpireOrderFormJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::ExpireOrderFormsJob
 * (app/jobs/clock/expire_order_forms_job.rb) — the hourly sweep fanning out
 * an ExpireOrderFormJob per expirable form.
 */
class ExpireOrderFormsJob implements ShouldQueue
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
        OrderForm::query()->expirable()->each(fn (OrderForm $orderForm) => ExpireOrderFormJob::dispatch($orderForm));
    }
}
