<?php

declare(strict_types=1);

use App\Models\InboundWebhook;
use Illuminate\Support\Facades\Queue;
use App\Jobs\InboundWebhooks\ProcessJob;
use App\Jobs\Clock\InboundWebhooksRetryJob;

uses()->group('ledger:job:Clock.InboundWebhooksRetryJob');

/**
 * Port of Rails' spec/jobs/clock/inbound_webhooks_retry_job_spec.rb — only
 * webhooks that fell out of the 2h processing window are re-fed: old
 * pending (never picked up) and processing past the window (lost worker);
 * failed/succeeded are never retried here.
 */
it('does not queue a pending webhook inside the window', function (): void {
    Queue::fake();
    InboundWebhook::factory()->create(['status' => 'pending', 'created_at' => now()->subMinutes(119)]);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertNotPushed(ProcessJob::class);
});

it('queues an old pending webhook past the window', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->create(['status' => 'pending', 'created_at' => now()->subHours(121)]);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertPushed(ProcessJob::class, fn (ProcessJob $job): bool => $job->inboundWebhook->is($inboundWebhook));
});

it('does not queue a processing webhook inside the window', function (): void {
    Queue::fake();
    InboundWebhook::factory()->create(['status' => 'processing', 'processing_at' => now()->subMinutes(119)]);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertNotPushed(ProcessJob::class);
});

it('queues a processing webhook past the window', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->create(['status' => 'processing', 'processing_at' => now()->subMinutes(121)]);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertPushed(ProcessJob::class, fn (ProcessJob $job): bool => $job->inboundWebhook->is($inboundWebhook));
});

it('does not queue a failed webhook', function (): void {
    Queue::fake();
    InboundWebhook::factory()->create(['status' => 'failed']);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertNotPushed(ProcessJob::class);
});

it('does not queue a succeeded webhook', function (): void {
    Queue::fake();
    InboundWebhook::factory()->create(['status' => 'succeeded']);

    (new InboundWebhooksRetryJob)->handle();

    Queue::assertNotPushed(ProcessJob::class);
});
