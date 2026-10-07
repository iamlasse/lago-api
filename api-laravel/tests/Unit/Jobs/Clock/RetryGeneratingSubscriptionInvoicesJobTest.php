<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Models\Subscription;
use App\Jobs\BillSubscriptionJob;
use App\Models\InvoiceSubscription;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\RetryGeneratingSubscriptionInvoicesJob;

uses()->group('ledger:job:Clock.RetryGeneratingSubscriptionInvoicesJob');

/**
 * Port of Rails' spec/jobs/clock/retry_generating_subscription_invoices_job_spec.rb —
 * subscription invoices stuck in the generating state for over a day are
 * re-enqueued for billing.
 */
function retryGeneratingSetup(array $invoiceOverrides = [], array $invoiceSubscriptionOverrides = []): array
{
    $invoice = Invoice::factory()->create(array_merge([
        'status' => InvoiceStatus::Generating,
        'created_at' => now('UTC')->subDays(2),
        'skip_charges' => true,
    ], $invoiceOverrides));

    $subscription = Subscription::factory()->create([
        'organization_id' => $invoice->organization_id,
        'customer_id' => $invoice->customer_id,
    ]);

    $invoiceSubscription = InvoiceSubscription::factory()->create(array_merge([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $invoice->organization_id,
        'timestamp' => now('UTC')->subDays(2),
        'invoicing_reason' => 'subscription_periodic',
    ], $invoiceSubscriptionOverrides));

    return [$invoice, $subscription, $invoiceSubscription];
}

it('re-enqueues billing for stuck generating subscription invoices', function (): void {
    Queue::fake();

    [$invoice, $subscription, $invoiceSubscription] = retryGeneratingSetup();

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertPushed(BillSubscriptionJob::class, fn (BillSubscriptionJob $job): bool => count($job->subscriptions) === 1
        && $job->subscriptions[0]->is($subscription)
        && $job->invoicingReason === 'subscription_periodic'
        && $job->invoiceId === $invoice->id
        && $job->skipCharges === true
        && $job->timestamp === Illuminate\Support\Facades\Date::parse($invoiceSubscription->timestamp)->getTimestamp());
});

it('skips invoices generated less than a day ago', function (): void {
    Queue::fake();

    retryGeneratingSetup(['created_at' => now('UTC')->subHour()]);

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertNotPushed(BillSubscriptionJob::class);
});

it('skips invoices not in the generating state', function (): void {
    Queue::fake();

    retryGeneratingSetup(['status' => InvoiceStatus::Finalized]);

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertNotPushed(BillSubscriptionJob::class);
});

it('skips invoices without invoice subscriptions', function (): void {
    Queue::fake();

    Invoice::factory()->create([
        'status' => InvoiceStatus::Generating,
        'created_at' => now('UTC')->subDays(2),
    ]);

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertNotPushed(BillSubscriptionJob::class);
});

it('skips in-advance-charge invoices', function (): void {
    Queue::fake();

    retryGeneratingSetup([], ['invoicing_reason' => 'in_advance_charge']);

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertNotPushed(BillSubscriptionJob::class);
});

it('uses the upgrading reason when the invoice subscriptions disagree', function (): void {
    Queue::fake();

    [$invoice, $subscription] = retryGeneratingSetup();

    $secondSubscription = Subscription::factory()->create([
        'organization_id' => $invoice->organization_id,
        'customer_id' => $invoice->customer_id,
    ]);

    InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $secondSubscription->id,
        'organization_id' => $invoice->organization_id,
        'timestamp' => now('UTC')->subDays(2),
        'invoicing_reason' => 'subscription_terminating',
    ]);

    (new RetryGeneratingSubscriptionInvoicesJob)->handle();

    Queue::assertPushed(BillSubscriptionJob::class, fn (BillSubscriptionJob $job): bool => $job->invoicingReason === 'upgrading');
});
