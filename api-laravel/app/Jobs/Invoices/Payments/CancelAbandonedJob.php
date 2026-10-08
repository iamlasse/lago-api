<?php

declare(strict_types=1);

namespace App\Jobs\Invoices\Payments;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\Payments\CancelAbandonedService;
use App\Services\PaymentProviders\Stripe\ApiConnectionError;
use App\Services\PaymentProviders\Stripe\RateLimitError as StripeRateLimitError;

/**
 * Port of Rails' Invoices::Payments::CancelAbandonedJob
 * (app/jobs/invoices/payments/cancel_abandoned_job.rb) — the per-payment
 * half of the abandoned-payment sweep, fanned out by
 * Clock::CancelAbandonedPaymentsJob with per-batch spacing.
 *
 * Rails retry_on: ::Stripe::RateLimitError / ::Stripe::APIConnectionError,
 * 6 attempts with polynomially longer waits (same table as
 * PaymentsCreateJob's ported precedent).
 */
class CancelAbandonedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $maxExceptions = 6;

    public function __construct(public readonly Payment $payment)
    {
        $this->onQueue(filter_var(env('SIDEKIQ_PAYMENTS'), FILTER_VALIDATE_BOOL) ? 'payments' : 'providers');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /** Rails: wait: :polynomially_longer — see PaymentsCreateJob precedent. */
    public function backoff(): array
    {
        return [15, 60, 135, 240, 375];
    }

    public function handle(): void
    {
        try {
            CancelAbandonedService::callBang(payment: $this->payment);
        } catch (StripeRateLimitError|ApiConnectionError $e) {
            // Retryable at the provider: bubble to the job's tries with the
            // backoff above (Rails: retry_on ... wait: :polynomially_longer,
            // attempts: 6). Any other exception fails the job.
            throw $e;
        }
    }
}
