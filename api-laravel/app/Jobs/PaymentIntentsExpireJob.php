<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentIntents\ExpireService;

/**
 * Port of Rails' PaymentIntents::ExpireJob — expires a settled invoice's
 * open hosted-checkout payment intents after commit.
 */
class PaymentIntentsExpireJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}

    public function handle(): void
    {
        ExpireService::callBang(invoice: $this->invoice);
    }
}
