<?php

declare(strict_types=1);

uses()->group(
    'ledger:consumer:EventsChargedInAdvanceConsumer',
    'ledger:job:Events.PayInAdvanceJob',
);

use App\Models\Event;
use App\Jobs\Events\PayInAdvanceJob;
use App\Services\Kafka\KafkaMessage;
use Illuminate\Support\Facades\Queue;
use App\Services\Events\Stores\ClickHouseStore;
use App\Services\Kafka\EventsChargedInAdvanceConsumer;

/**
 * Port of Rails' spec/consumers/events_charged_in_advance_consumer_spec.rb
 * — the ClickHouse dual-write path: each charged-in-advance message
 * re-dispatches one pay-in-advance job, delayed past the ClickHouse merge.
 * (The message payload hash re-enters the port as an in-memory Event, the
 * shape the landed Events\PayInAdvanceJob carries.)
 */
it('enqueues a pay in advance job with the clickhouse merge delay', function (): void {
    $payload = [
        'id' => '11111111-1111-4111-8111-111111111111',
        'organization_id' => '22222222-2222-4222-8222-222222222222',
        'transaction_id' => 'txn_1',
        'external_subscription_id' => 'sub_1',
        'timestamp' => '1727712000.123456',
        'timestamp_with_precision' => '2024-09-30T16:00:00.123456789Z',
        'code' => 'api_calls',
        'properties' => ['calls' => 5],
    ];

    $message = new KafkaMessage('charged_in_advance', json_encode($payload, JSON_THROW_ON_ERROR));

    (new EventsChargedInAdvanceConsumer)->consume([$message]);

    Queue::assertPushed(PayInAdvanceJob::class, function (PayInAdvanceJob $job): bool {
        /** @var Event $event */
        $event = $job->event;

        return $event->transaction_id === 'txn_1'
            && $event->organization_id === '22222222-2222-4222-8222-222222222222'
            && $event->external_subscription_id === 'sub_1'
            && $event->code === 'api_calls'
            && $event->properties === ['calls' => 5]
            // Rails: `at(CLICKHOUSE_MERGE_DELAY.from_now)` — 15 seconds out.
            && $job->delay !== null
            && abs($job->delay->getTimestamp() - now()->addSeconds(EventsChargedInAdvanceConsumer::MERGE_DELAY_SECONDS)->getTimestamp()) < 2;
    });
});

it('matches the rails clickhouse merge delay constant', function (): void {
    expect(EventsChargedInAdvanceConsumer::MERGE_DELAY_SECONDS)
        ->toBe(ClickHouseStore::MERGE_DELAY_SECONDS);
});

it('rebuilds the timestamp through the precision field when present', function (): void {
    $payload = [
        'organization_id' => '22222222-2222-4222-8222-222222222222',
        'transaction_id' => 'txn_precision',
        'timestamp' => '1727712000',
        'timestamp_with_precision' => '2024-09-30T16:00:00.123456789Z',
    ];

    (new EventsChargedInAdvanceConsumer)->consume([
        new KafkaMessage('charged_in_advance', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    Queue::assertPushed(PayInAdvanceJob::class, function (PayInAdvanceJob $job): bool {
        /** @var Event $event */
        $event = $job->event;

        return $event->timestamp->toIso8601String() === '2024-09-30T16:00:00+00:00'
            && (float) $event->timestamp->format('U.u') === 1727712000.123456;
    });
});

it('skips a non-json payload without enqueuing', function (): void {
    (new EventsChargedInAdvanceConsumer)->consume([
        new KafkaMessage('charged_in_advance', '{not json'),
        new KafkaMessage('charged_in_advance', null),
    ]);

    Queue::assertNotPushed(PayInAdvanceJob::class);
});
