<?php

declare(strict_types=1);

namespace App\Jobs\PaymentReceipts;

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Shared queue selection for the payment-receipt document jobs — Rails'
 * `queue_as` blocks: the dedicated :pdfs queue when SIDEKIQ_PDFS is set,
 * :low_priority otherwise (Rails' PaymentReceipts::*Job queue_as).
 */
abstract class DocumentsJob implements ShouldQueue
{
    use Queueable;

    /** Rails: max attempts for the Gotenberg-dependent jobs. */
    public int $tries = 6;

    public function __construct()
    {
        $this->onQueue(static::queueName());
    }

    /** Rails: `queue_as { SIDEKIQ_PDFS ? :pdfs : :low_priority }`. */
    public static function queueName(): string
    {
        return filter_var(env('SIDEKIQ_PDFS'), FILTER_VALIDATE_BOOL) ? 'pdfs' : 'low_priority';
    }

    /**
     * Rails: retry_on ..., wait: :polynomially_longer — (executions ** 4) + 2
     * seconds between attempts, as an explicit backoff table for the 5
     * retries.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [3, 18, 83, 258, 627];
    }
}
