<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Invoices\Payments\CreateService;
use App\Services\Invoices\Payments\RetriableError;

/**
 * Port of Rails' Invoices::Payments::CreateJob — the async payment attempt
 * enqueued at invoice finalization (queue :payments when SIDEKIQ_PAYMENTS
 * is set).
 *
 * Rails retry_on: ConnectionError / RateLimitError, 6 attempts with
 * polynomially longer waits; the unique-until-executed lock is keyed on
 * the invoice.
 */
class PaymentsCreateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 6;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?string $paymentProvider = null,
        public readonly array $paymentMethodParams = [],
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_PAYMENTS'), FILTER_VALIDATE_BOOL) ? 'payments' : 'default');
    }

    public function backoff(): array
    {
        return [15, 60, 135, 240, 375];
    }

    public function handle(): void
    {
        try {
            CreateService::callBang(
                invoice: $this->invoice,
                paymentProvider: $this->paymentProvider,
                paymentMethodParams: $this->paymentMethodParams,
            );
        } catch (RetriableError) {
            // The create service signalled a retryable failure — release for
            // another attempt (Rails: retry_on Invoices::Payments::*Error).
            $this->release(60);
        }
        // RateLimitError / ConnectionError bubble up and consume the job's
        // tries with the backoff above, like Rails' retry_on stanzas.
    }

    public function uniqueId(): string
    {
        return (string) $this->invoice->id;
    }
}
