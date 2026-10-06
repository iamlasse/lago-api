<?php

declare(strict_types=1);

namespace App\Jobs\PaymentRequests;

use App\Models\PaymentRequest;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\PaymentRequests\Payments\CreateService;

/**
 * Port of Rails' PaymentRequests::Payments::CreateJob — the async payment
 * attempt enqueued when a payment request is created (queue :payments when
 * SIDEKIQ_PAYMENTS is set).
 *
 * Rails retry_on: ConnectionError / RateLimitError, 6 attempts with
 * polynomially longer waits; the unique-until-executed lock is keyed on
 * the payment request.
 */
class PaymentsCreateJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 6;

    public function __construct(
        public readonly PaymentRequest $payable,
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
        CreateService::callBang(
            payable: $this->payable,
            paymentProvider: $this->paymentProvider,
            paymentMethodParams: $this->paymentMethodParams,
        );
    }

    public function uniqueId(): string
    {
        return (string) $this->payable->id;
    }
}
