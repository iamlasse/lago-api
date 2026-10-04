<?php

declare(strict_types=1);

namespace App\Jobs\Fees;

use Throwable;
use RuntimeException;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Events\PayInAdvanceArguments;
use App\Services\Fees\CreatePayInAdvanceService;

/**
 * Port of Rails' Fees::CreatePayInAdvanceJob
 * (app/jobs/fees/create_pay_in_advance_job.rb) — bills a NON-invoiceable
 * pay-in-advance event into a standalone fee.
 *
 * Rails retries on the ClickHouse store's throttling/memory errors — the
 * Postgres store has no such failure modes (TODO(port): restore with the
 * ClickHouse store).
 */
class CreatePayInAdvanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly string $chargeId,
        public readonly string $eventId,
    ) {
        $this->onQueue('default');
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $arguments = new PayInAdvanceArguments(chargeId: $this->chargeId, eventId: $this->eventId);

        $billingContext = $arguments->billingContext();

        if ($billingContext === null) {
            $this->skipMissingBillingContext($arguments);

            return;
        }

        CreatePayInAdvanceService::callBang(
            meteredItem: $arguments->meteredItem(),
            billingContext: $billingContext,
            event: $arguments->event(),
        );
    }

    /**
     * Rails: `skip_missing_billing_context` — the subscription terminated
     * between ingestion and perform; logged, not retried.
     */
    private function skipMissingBillingContext(PayInAdvanceArguments $arguments): void
    {
        $event = $arguments->event();

        report(new RuntimeException(sprintf(
            'Fees::CreatePayInAdvanceJob skipped: no billing context for event organization_id=%s external_subscription_id=%s event_transaction_id=%s',
            (string) $event->organization_id,
            (string) $event->external_subscription_id,
            (string) $event->transaction_id,
        )));
    }
}
