<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Invoices\Payments\CancelAbandonedJob;
use App\Services\Invoices\Payments\CancelAbandonedService;

/**
 * Port of Rails' Clock::CancelAbandonedPaymentsJob
 * (app/jobs/clock/cancel_abandoned_payments_job.rb) — hourly sweep for
 * invoice payments stuck in "requires_action" (3DS redirect never came
 * back): each candidate is handed to the per-payment cancellation job,
 * batched with increasing spacing so the PSP work arrives as a trickle.
 */
class CancelAbandonedPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BATCH_SIZE = 100;

    /** Rails: SPACING = 1.minute (per-batch dispatch delay). */
    public const SPACING_SECONDS = 60;

    public function __construct()
    {
        $this->onQueue('clock');
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

    public function handle(): void
    {
        $this->candidates()->chunkById(
            self::BATCH_SIZE,
            function ($payments, int $page): void {
                foreach ($payments as $payment) {
                    CancelAbandonedJob::dispatch($payment)
                        ->delay(now()->addSeconds(($page - 1) * self::SPACING_SECONDS));
                }
            },
            'payments.id',
            'id',
        );
    }

    /**
     * Rails: #candidates — narrowed to what the service can ever act on.
     * Rails' joins(:payment_provider) rides the Discard default scope
     * (kept rows only), so a discarded provider drops out here too.
     */
    private function candidates()
    {
        return Payment::query()
            ->where('payments.payment_type', 'provider')
            ->join('payment_providers', 'payments.payment_provider_id', '=', 'payment_providers.id')
            ->whereNull('payment_providers.deleted_at')
            ->where('payments.payable_type', 'Invoice')
            ->where('payment_providers.type', 'PaymentProviders::StripeProvider')
            ->where('payments.payable_payment_status', 'processing')
            ->where('payments.status', 'requires_action')
            ->whereBetween(
                'payments.updated_at',
                CancelAbandonedService::recoveryRange(),
            )
            ->select('payments.*');
    }
}
