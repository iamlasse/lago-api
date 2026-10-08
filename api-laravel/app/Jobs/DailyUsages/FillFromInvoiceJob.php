<?php

declare(strict_types=1);

namespace App\Jobs\DailyUsages;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DailyUsages\FillFromInvoiceService;

/**
 * Port of Rails' DailyUsages::FillFromInvoiceJob
 * (app/jobs/daily_usages/fill_from_invoice_job.rb) — fills the daily
 * usages covered by an invoice after it is created; analytics queue when
 * SIDEKIQ_ANALYTICS is set, like every queue-split job.
 */
class FillFromInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly Invoice $invoice,
        /** @var list<Subscription> */
        public readonly array $subscriptions,
    ) {
        $this->onQueue(
            filter_var(env('SIDEKIQ_ANALYTICS'), FILTER_VALIDATE_BOOL) ? 'analytics' : 'low_priority'
        );
    }

    public function handle(): void
    {
        FillFromInvoiceService::callBang(
            invoice: $this->invoice,
            subscriptions: $this->subscriptions,
        );
    }
}
