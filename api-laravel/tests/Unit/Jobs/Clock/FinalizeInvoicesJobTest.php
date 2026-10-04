<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Jobs\Invoices\FinalizeJob;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\FinalizeInvoicesJob;

uses()->group('ledger:job:Clock.FinalizeInvoicesJob');

/**
 * Port of Rails' spec/jobs/clock/finalize_invoices_job_spec.rb — the clock
 * fans out one Invoices::FinalizeJob per draft invoice whose expected
 * finalization date (or issuing date) has come.
 */
it('enqueues finalize jobs for draft invoices ready to be finalized', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->draft()->create([
        'issuing_date' => now('UTC')->subDay()->toDateString(),
    ]);

    (new FinalizeInvoicesJob)->handle();

    Queue::assertPushed(FinalizeJob::class, fn (FinalizeJob $job): bool => $job->invoice->is($invoice));
});

it('skips draft invoices whose expected finalization date is in the future', function (): void {
    Queue::fake();

    Invoice::factory()->draft()->create([
        'issuing_date' => now('UTC')->toDateString(),
        'expected_finalization_date' => now('UTC')->addDay()->toDateString(),
    ]);

    (new FinalizeInvoicesJob)->handle();

    Queue::assertNotPushed(FinalizeJob::class);
});

it('uses the expected finalization date over the issuing date', function (): void {
    Queue::fake();

    $invoice = Invoice::factory()->draft()->create([
        'issuing_date' => now('UTC')->addMonth()->toDateString(),
        'expected_finalization_date' => now('UTC')->subDay()->toDateString(),
    ]);

    (new FinalizeInvoicesJob)->handle();

    Queue::assertPushed(FinalizeJob::class, fn (FinalizeJob $job): bool => $job->invoice->is($invoice));
});

it('skips invoices that are not drafts', function (): void {
    Queue::fake();

    Invoice::factory()->create([
        'issuing_date' => now('UTC')->subYear()->toDateString(),
    ]);

    (new FinalizeInvoicesJob)->handle();

    Queue::assertNotPushed(FinalizeJob::class);
});
