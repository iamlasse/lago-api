<?php

declare(strict_types=1);

namespace App\Jobs\BillingSegments;

use App\Models\Customer;
use App\Models\BillingSegment;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\BillingSegments\ScheduleService;

/**
 * Port of Rails' BillingSegments::ScheduleJob (app/jobs/billing_segments/
 * schedule_job.rb) — produces a customer's due segments, then chains the
 * consumer when there is something to invoice.
 */
class ScheduleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $customerId,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    /** Port of `unique :until_executing, on_conflict: :log, lock_ttl: 30.minutes`. */
    public function uniqueFor(): int
    {
        return 30 * 60;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        ScheduleService::callBang(
            customer: Customer::query()->findOrFail($this->customerId),
        );

        if (BillingSegment::query()
            ->awaitingInvoicing()
            ->where('billing_segments.customer_id', $this->customerId)
            ->exists()) {
            dispatch(new ProcessJob($this->customerId));
        }
    }
}
