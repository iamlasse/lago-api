<?php

declare(strict_types=1);

namespace App\Jobs\OrderForms;

use Throwable;
use App\Models\OrderForm;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\OrderForms\ExpireService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' OrderForms::ExpireOrderFormJob
 * (app/jobs/order_forms/expire_order_form_job.rb) — expires one order form.
 *
 * Rails retries on BaseLockService::FailedToAcquireLock; the port logs and
 * drops the form until the next clock sweep, matching the job's
 * `on_conflict: :log` character.
 */
class ExpireOrderFormJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private readonly OrderForm $orderForm,
    ) {}

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
        try {
            ExpireService::callBang(orderForm: $this->orderForm);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
