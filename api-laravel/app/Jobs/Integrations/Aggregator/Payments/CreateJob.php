<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Aggregator\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Aggregator\Payments\CreateService;

/**
 * Port of Rails' Integrations::Aggregator::Payments::CreateJob
 * (app/jobs/integrations/aggregator/payments/create_job.rb).
 *
 * TODO(port): `unique :until_executed`, the ConcurrencyThrottlable concern
 * and the retry_on table (HttpError 5 attempts polynomial, PayloadFailure 10,
 * RequestLimitError 100, ThrottlingError 25) — same queue-hardening debt as
 * the invoices job.
 */
class CreateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Payment $payment,
    ) {
        $this->onQueue('integrations');
    }

    /** Rails: `perform_later(payment:) if payment.should_sync_payment?`. */
    public static function dispatchIfShouldSync(Payment $payment): void
    {
        if ($payment->shouldSyncPayment()) {
            dispatch(new self($payment));
        }
    }

    public function handle(): void
    {
        CreateService::callBang(payment: $this->payment);
    }
}
