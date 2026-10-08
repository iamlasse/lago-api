<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Support\Carbon;
use App\Models\ContractRateCard;
use App\Jobs\Middleware\UniqueJob;
use App\Jobs\BillingSegments\ScheduleJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Port of Rails' Clock::CreateBillingSegmentsJob (app/jobs/clock/
 * create_billing_segments_job.rb) — fans the producer out, one job per
 * customer with a due card. A customer already queued or running is dropped
 * rather than queued again, and two that do overlap are made safe by the
 * lock and the transaction the per-customer run holds.
 */
class CreateBillingSegmentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /**
     * Port of `unique :until_executed, on_conflict: :log, lock_ttl:
     * 30.minutes` — the tick is hourly and a run takes seconds. A shorter ttl
     * than the tick means a crashed run expires its lock before the next tick
     * instead of costing one.
     */
    public function uniqueFor(): int
    {
        return 30 * 60;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /**
     * One enqueue per due customer, in a single run. A normal tick has few; a
     * first run or a tick after an outage has as many as there are customers
     * with a due card. Paging the scan and re-enqueueing itself is the way
     * out if that ever hurts.
     */
    public function handle(): void
    {
        foreach ($this->dueCustomerIds() as $customerId) {
            dispatch(new ScheduleJob($customerId));
        }
    }

    /** Rails: `#due_customer_ids`. */
    private function dueCustomerIds(): array
    {
        // The scope pre-sets its own select columns, so pluck's column must
        // be re-declared explicitly (onceWithColumns ignores it otherwise).
        return ContractRateCard::query()
            ->dueForBilling(Carbon::now())
            ->distinct()
            ->select('contracts.customer_id')
            ->pluck('customer_id')
            ->all();
    }
}
