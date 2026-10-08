<?php

declare(strict_types=1);

namespace App\Jobs\BillingSegments;

use App\Models\Customer;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\BillingSegments\ProcessService;

/**
 * Port of Rails' BillingSegments::ProcessJob (app/jobs/billing_segments/
 * process_job.rb) — invoices a customer's due segments.
 */
class ProcessJob implements ShouldQueue
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
        ProcessService::callBang(
            customer: Customer::query()->findOrFail($this->customerId),
        );
    }
}
