<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use RuntimeException;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Events\PayInAdvanceArguments;
use App\Services\Invoices\CreatePayInAdvanceChargeService;

/**
 * Port of Rails' Invoices::CreatePayInAdvanceChargeJob
 * (app/jobs/invoices/create_pay_in_advance_charge_job.rb) — bills an
 * invoiceable pay-in-advance event into its own invoice.
 */
class CreatePayInAdvanceChargeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly mixed $timestamp,
        public readonly string $chargeId,
        public readonly string $eventId,
    ) {
        $this->onQueue(
            filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default',
        );
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        $arguments = new PayInAdvanceArguments(chargeId: $this->chargeId, eventId: $this->eventId);

        $billingContext = $arguments->billingContext();

        if ($billingContext === null) {
            report(new RuntimeException(sprintf(
                'Invoices::CreatePayInAdvanceChargeJob skipped: no billing context for event organization_id=%s external_subscription_id=%s',
                (string) $arguments->event()->organization_id,
                (string) $arguments->event()->external_subscription_id,
            )));

            return;
        }

        CreatePayInAdvanceChargeService::callBang(
            timestamp: $this->timestamp,
            meteredItem: $arguments->meteredItem(),
            billingContext: $billingContext,
            event: $arguments->event(),
        );
    }
}
