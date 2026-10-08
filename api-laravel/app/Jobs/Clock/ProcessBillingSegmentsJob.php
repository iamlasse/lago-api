<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\BillingSegment;
use App\Jobs\Middleware\UniqueJob;
use App\Jobs\BillingSegments\ProcessJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Port of Rails' Clock::ProcessBillingSegmentsJob (app/jobs/clock/
 * process_billing_segments_job.rb) — five minutes behind the producer, so a
 * card that comes due is invoiced in the same hour. A fan-out that runs long
 * only defers its stragglers to the next tick; nothing is lost.
 */
class ProcessBillingSegmentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 30.minutes`. */
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
        foreach ($this->pendingCustomerIds() as $customerId) {
            dispatch(new ProcessJob($customerId));
        }
    }

    /** Rails: `#pending_customer_ids`. */
    private function pendingCustomerIds(): array
    {
        // The awaitingInvoicing scope pre-sets its own select columns, so
        // pluck's column must be re-declared explicitly.
        return BillingSegment::query()
            ->awaitingInvoicing()
            ->join('customers', 'customers.id', '=', 'billing_segments.customer_id')
            ->whereNull('customers.deleted_at')
            ->distinct()
            ->select('billing_segments.customer_id')
            ->pluck('customer_id')
            ->all();
    }
}
