<?php

declare(strict_types=1);

namespace App\Jobs\PaymentReceipts;

use App\Models\Payment;
use App\Services\PaymentReceipts\CreateService;

/**
 * Port of Rails' PaymentReceipts::CreateJob
 * (app/jobs/payment_receipts/create_job.rb, queue :low_priority) —
 * PaymentReceipts::CreateService.call!(payment:).
 */
class CreateJob extends DocumentsJob
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        CreateService::callBang(payment: $this->payment);
    }
}
