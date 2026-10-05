<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions\ActivationRules\Payment;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Subscriptions\ActivationRules\Payment\ResolveService;

/**
 * Port of Rails' Subscriptions::ActivationRules::Payment::ResolveJob
 * (app/jobs/subscriptions/activation_rules/payment/resolve_job.rb).
 */
class ResolveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Invoice $invoice,
        public readonly string $paymentStatus, // 'succeeded' | 'failed'
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function handle(): void
    {
        ResolveService::callBang(
            subscription: Subscription::query()->find($this->subscription->id) ?? $this->subscription,
            invoice: Invoice::query()->find($this->invoice->id) ?? $this->invoice,
            paymentStatus: $this->paymentStatus,
        );
    }
}
