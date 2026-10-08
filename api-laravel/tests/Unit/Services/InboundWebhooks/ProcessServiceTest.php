<?php

declare(strict_types=1);

use App\Models\InboundWebhook;
use Illuminate\Support\Facades\Queue;
use App\Jobs\InboundWebhooks\ProcessJob;
use App\Services\InboundWebhooks\ProcessService;
use App\Jobs\PaymentProviders\StripeHandleEventJob;

uses()->group('ledger:svc:InboundWebhooks.ProcessService');

/**
 * Port of Rails' spec/services/inbound_webhooks/process_service_spec.rb —
 * the status-guarded wrapper: within-window/failed/succeeded webhooks are
 * never reprocessed; a valid one moves pending → processing → succeeded
 * and delegates to the source handler; a handler failure marks it failed
 * and returns the handler's result; an unknown source marks it failed and
 * raises.
 */
it('marks the webhook processing and delegates to the Stripe handler', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create();

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeTrue();
    expect($result->inbound_webhook->is($inboundWebhook))->toBeTrue();
    expect($inboundWebhook->refresh()->status)->toBe('succeeded');

    Queue::assertPushed(StripeHandleEventJob::class);
});

it('flags the webhook failed and raises when the source is invalid', function (): void {
    $inboundWebhook = InboundWebhook::factory()->create(['source' => 'invalid_source']);

    ProcessService::call(inboundWebhook: $inboundWebhook);
})->throws(RuntimeException::class, 'Invalid inbound webhook source: invalid_source');

it('flags the webhook failed when the source is invalid', function (): void {
    $inboundWebhook = InboundWebhook::factory()->create(['source' => 'invalid_source']);

    try {
        ProcessService::call(inboundWebhook: $inboundWebhook);
    } catch (RuntimeException) {
        // Rails raises NameError after failed!; the state transition is the
        // observable half of the contract.
    }

    expect($inboundWebhook->refresh()->status)->toBe('failed');
});

it('does not process a webhook inside the processing window', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create([
        'status' => 'processing',
        'processing_at' => now()->subMinutes(119),
    ]);

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeTrue();
    expect($inboundWebhook->refresh()->status)->toBe('processing');
    Queue::assertNotPushed(StripeHandleEventJob::class);
});

it('processes a webhook outside the processing window as normal', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create([
        'status' => 'processing',
        'processing_at' => now()->subMinutes(121),
    ]);

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeTrue();
    expect($inboundWebhook->refresh()->status)->toBe('succeeded');
});

it('does not process a webhook that has failed', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create(['status' => 'failed']);

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeTrue();
    expect($inboundWebhook->refresh()->status)->toBe('failed');
    Queue::assertNotPushed(StripeHandleEventJob::class);
});

it('does not process a webhook that has succeeded', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create(['status' => 'succeeded']);

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeTrue();
    expect($inboundWebhook->refresh()->status)->toBe('succeeded');
    Queue::assertNotPushed(StripeHandleEventJob::class);
});

it('returns the handler result and marks the webhook failed when Stripe handling fails', function (): void {
    Queue::fake();
    // Valid JSON whose top level is not an object is the real Stripe
    // handler's failure path (webhook_error ServiceFailure) — the payload
    // column is jsonb, so the JSON itself must parse.
    $inboundWebhook = InboundWebhook::factory()->stripe()->create(['payload' => '"just a string"']);

    $result = ProcessService::call(inboundWebhook: $inboundWebhook);

    expect($result->success())->toBeFalse();
    expect($inboundWebhook->refresh()->status)->toBe('failed');
    Queue::assertNotPushed(StripeHandleEventJob::class);
});

it('queues the process job from the retry clock entrypoint', function (): void {
    Queue::fake();
    $inboundWebhook = InboundWebhook::factory()->stripe()->create(['status' => 'processing', 'processing_at' => now()->subMinutes(121)]);

    (new App\Jobs\Clock\InboundWebhooksRetryJob)->handle();

    Queue::assertPushed(ProcessJob::class, fn (ProcessJob $job): bool => $job->inboundWebhook->is($inboundWebhook));
});
