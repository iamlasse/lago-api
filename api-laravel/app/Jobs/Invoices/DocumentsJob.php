<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Shared queue selection for the document jobs — Rails' `queue_as` blocks:
 * the dedicated :pdfs queue when SIDEKIQ_PDFS is set, :invoices otherwise.
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

    /** Rails: `queue_as { SIDEKIQ_PDFS ? :pdfs : :invoices }`. */
    public static function queueName(): string
    {
        return filter_var(env('SIDEKIQ_PDFS'), FILTER_VALIDATE_BOOL) ? 'pdfs' : 'invoices';
    }

    /**
     * Rails: retry_on ..., wait: :polynomially_longer — ActiveJob computes
     * (executions ** 4) + 2 seconds between attempts. Laravel takes an
     * explicit backoff table for the 5 retries.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [3, 18, 83, 258, 627];
    }
}
