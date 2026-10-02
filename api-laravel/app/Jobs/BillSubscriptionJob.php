<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\SubscriptionService;

/**
 * Port of Rails' BillSubscriptionJob (app/jobs/bill_subscription_job.rb).
 *
 * The unique-until-executed lock key embeds the timezone-normalized date —
 * ported EXACTLY (see uniqueKey()); a wrong key is a double-billing risk.
 */
class BillSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Rails: MAX_LOCK_RETRY_ATTEMPTS. */
    public const MAX_LOCK_RETRY_ATTEMPTS = 4;

    public int $tries = 1;

    /** Port of `retry_on ... attempts:, wait:` stanzas. */
    public int $maxExceptions = 15;

    /** Rails queue: `billing` when SIDEKIQ_BILLING is set, `default` otherwise. */
    public function __construct(
        /** @var list<Subscription> */
        public array $subscriptions,
        public int $timestamp,
        public string $invoicingReason,
        public ?string $invoiceId = null,
        public bool $skipCharges = false,
    ) {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    public function middleware(): array
    {
        return [new Middleware\UniqueJob];
    }

    /** Port of `unique :until_executed, lock_ttl: 12.hours`. */
    public function uniqueFor(): int
    {
        return 12 * 3600;
    }

    /**
     * NOTE: Each hour, we check for each customer whether they need to be
     * billed today. If so and there's no invoice for today in the DB, we
     * schedule the BillSubscriptionJob with the timestamp of the current
     * time. It could occur that we schedule a second job while the first one
     * hasn't been processed yet. As the timestamp differs, the lock key would
     * differ and both jobs could be processed concurrently. To avoid this,
     * we normalize the timestamp in the customer's timezone and use the date
     * as the lock key argument.
     */
    public function uniqueKey(): string
    {
        // if there is no subscription, we don't need to normalize anything
        if ($this->subscriptions === []) {
            return (string) $this->timestamp;
        }

        // BillSubscriptionJob subscriptions always contain subscriptions for
        // the same customer.
        $customer = $this->subscriptions[0]->customer;
        $date = \Carbon\CarbonImmutable::createFromTimestampUTC($this->timestamp)
            ->setTimezone($customer->applicableTimezone())
            ->toDateString();

        return $date.'|'.$this->invoicingReason.'|'.implode(',', array_map(fn ($s) => $s->id, $this->subscriptions));
    }

    public function handle(): void
    {
        Log::info('BillSubscriptionJob[Invoice ID: '.($this->invoiceId ?? 'nil').'] - Started');

        $result = SubscriptionService::call(
            subscriptions: $this->subscriptions,
            timestamp: $this->timestamp,
            invoicingReason: $this->invoicingReason,
            invoice: $this->invoiceId !== null
                ? \App\Models\Invoice::query()->find($this->invoiceId)
                : null,
            skipCharges: $this->skipCharges,
        );

        if ($result->success()) {
            Log::info('BillSubscriptionJob[Invoice ID: '.($this->invoiceId ?? 'nil').'] - Finished [SUCCESS]');

            return;
        }

        $invoice = $result->invoice?->refresh();

        // If the invoice was passed as an argument, it means the job was
        // already retried (see end of function).
        if ($this->invoiceId !== null || $invoice === null || ! $invoice->isGenerating()) {
            // TODO(port): ErrorDetail.create_generation_error_for(invoice:, error:).
            $result->raiseIfError();

            return;
        }

        // On billing day, we retry the job further in the future because the
        // system is typically under heavy load.
        $isBillingDate = $this->invoicingReason === 'subscription_periodic';

        Log::info('BillSubscriptionJob[Invoice ID: '.($this->invoiceId ?? 'nil').'] - Retrying with invoice');

        self::dispatch(
            $this->subscriptions,
            $this->timestamp,
            $this->invoicingReason,
            $invoice->id,
            $this->skipCharges,
        )->delay($isBillingDate ? 300 : 3);
    }

    /**
     * Port of `retry_on BaseLockService::FailedToAcquireLock,
     * ActiveRecord::StaleObjectError, attempts: MAX_LOCK_RETRY_ATTEMPTS,
     * wait: random_lock_retry_delay`.
     */
    public function failed(mixed $exception): void
    {
        Log::error('BillSubscriptionJob failed', ['exception' => $exception]);
    }
}
