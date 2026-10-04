<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Invoices\RefreshDraftJob;
use App\Jobs\Clock\RefreshDraftInvoicesJob;

uses()->group('ledger:job:Clock.RefreshDraftInvoicesJob');

/**
 * Port of Rails' spec/jobs/clock/refresh_draft_invoices_job_spec.rb — the
 * clock fans out one Invoices::RefreshDraftJob per draft invoice flagged
 * ready_to_be_refreshed that still has an active subscription.
 */
function refreshDraftSetup(array $invoiceOverrides = [], array $subscriptionOverrides = []): Invoice
{
    $invoice = Invoice::factory()->draft()->create(array_merge([
        'ready_to_be_refreshed' => true,
    ], $invoiceOverrides));

    $subscription = Subscription::factory()->create(array_merge([
        'organization_id' => $invoice->organization_id,
        'customer_id' => $invoice->customer_id,
    ], $subscriptionOverrides));

    // invoice_subscriptions carries NOT NULL boundaries — the factory (not
    // a bare attach) must build the join row.
    App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $invoice->organization_id,
    ]);

    return $invoice;
}

it('enqueues refresh jobs for flagged draft invoices with active subscriptions', function (): void {
    Queue::fake();

    $invoice = refreshDraftSetup();

    (new RefreshDraftInvoicesJob)->handle();

    Queue::assertPushed(RefreshDraftJob::class, fn (RefreshDraftJob $job): bool => $job->invoice->is($invoice));
});

it('skips invoices without an active subscription', function (): void {
    Queue::fake();

    refreshDraftSetup([], ['status' => App\Enums\SubscriptionStatus::Terminated]);

    (new RefreshDraftInvoicesJob)->handle();

    Queue::assertNotPushed(RefreshDraftJob::class);
});

it('skips invoices not flagged ready_to_be_refreshed', function (): void {
    Queue::fake();

    refreshDraftSetup(['ready_to_be_refreshed' => false]);

    (new RefreshDraftInvoicesJob)->handle();

    Queue::assertNotPushed(RefreshDraftJob::class);
});

it('skips invoices that are not drafts', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->create(['ready_to_be_refreshed' => true]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $invoice->organization_id,
        'customer_id' => $invoice->customer_id,
    ]);
    App\Models\InvoiceSubscription::factory()->create([
        'invoice_id' => $invoice->id,
        'subscription_id' => $subscription->id,
        'organization_id' => $invoice->organization_id,
    ]);

    (new RefreshDraftInvoicesJob)->handle();

    Queue::assertNotPushed(RefreshDraftJob::class);
});
