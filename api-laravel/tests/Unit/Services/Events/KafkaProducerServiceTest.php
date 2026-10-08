<?php

declare(strict_types=1);

uses()->group('ledger:svc:Events.KafkaProducerService');

use App\Models\Event;
use App\Models\Organization;
use App\Services\Events\KafkaProducerService;
use App\Services\Kafka\Contracts\KafkaProducerTransport;

/**
 * Port of Rails' spec/services/events/kafka_producer_service_spec.rb —
 * the raw-events producer: env-guarded, one message per event, the
 * ClickHouse-shaped payload (float timestamp without 'Z', defaulted
 * precise amount, millisecond ingested_at, source http_ruby).
 */
final class ProducerTransportSpy implements KafkaProducerTransport
{
    /** @var list<array{topic: string, payload: string}> */
    public array $produced = [];

    public function produce(string $topic, string $payload): void
    {
        $this->produced[] = ['topic' => $topic, 'payload' => $payload];
    }
}

function producerEnvironment(array $config = []): array
{
    $organization = Organization::factory()->create([
        'clickhouse_events_store' => true,
    ]);

    $event = new Event();
    $event->organization_id = $organization->id;
    $event->transaction_id = 'txn_kafka';
    $event->external_subscription_id = 'sub_kafka';
    $event->external_customer_id = 'cust_kafka';
    $event->code = 'api_calls';
    $event->properties = ['calls' => 3];
    $event->timestamp = Carbon\CarbonImmutable::parse('2024-09-30T16:00:00.123456+00:00');
    $event->precise_total_amount_cents = '123.45';

    config(array_merge([
        'lago.kafka.bootstrap_servers' => 'kafka:9092',
        'lago.kafka.raw_events_topic' => 'raw_events',
    ], $config));

    return [$organization, $event];
}

it('produces one raw event message per event', function (): void {
    [$organization, $event] = producerEnvironment();
    $transport = new ProducerTransportSpy;

    KafkaProducerService::call(events: [$event], organization: $organization);

    // The service defaults to the Pending transport through the container;
    // assert through the transport seam instead by building the payload.
    $service = new KafkaProducerService([$event], $organization, $transport);
    $service->execute();

    expect($transport->produced)->toHaveCount(1)
        ->and($transport->produced[0]['topic'])->toBe('raw_events');

    $payload = json_decode($transport->produced[0]['payload'], true, 512, JSON_THROW_ON_ERROR);

    expect($payload['organization_id'])->toBe($organization->id)
        ->and($payload['transaction_id'])->toBe('txn_kafka')
        ->and($payload['external_subscription_id'])->toBe('sub_kafka')
        ->and($payload['external_customer_id'])->toBe('cust_kafka')
        ->and($payload['code'])->toBe('api_calls')
        ->and($payload['properties'])->toBe(['calls' => 3])
        // Rails: `event.timestamp.to_f.to_s` — fractional seconds, no 'Z'.
        ->and($payload['timestamp'])->toBe('1727712000.123456')
        ->and($payload['precise_total_amount_cents'])->toBe('123.45')
        ->and($payload['source'])->toBe('http_ruby')
        // The org reads the clickhouse store, so the event needs post-processing.
        ->and($payload['source_metadata']['api_post_processed'])->toBeFalse()
        // Rails: Time.zone.now.iso8601(3)[...-1] — millisecond iso8601, no 'Z'.
        ->and($payload['ingested_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}$/');
});

it('defaults the precise amount to 0.0 for clickhouse parsing', function (): void {
    [$organization, $event] = producerEnvironment();
    $event->precise_total_amount_cents = null;
    $transport = new ProducerTransportSpy;

    (new KafkaProducerService([$event], $organization, $transport))->execute();

    expect(json_decode($transport->produced[0]['payload'], true)['precise_total_amount_cents'])->toBe('0.0');
});

it('marks the payload post-processed when the organization reads postgres', function (): void {
    [$organization, $event] = producerEnvironment();
    $organization->forceFill(['clickhouse_events_store' => false])->save();
    $transport = new ProducerTransportSpy;

    (new KafkaProducerService([$event], $organization, $transport))->execute();

    expect(json_decode($transport->produced[0]['payload'], true)['source_metadata']['api_post_processed'])->toBeTrue();
});

it('is a no-op without the bootstrap servers configured', function (): void {
    [$organization, $event] = producerEnvironment(['lago.kafka.bootstrap_servers' => null]);
    $transport = new ProducerTransportSpy;

    (new KafkaProducerService([$event], $organization, $transport))->execute();

    expect($transport->produced)->toBe([]);
});

it('is a no-op without the raw events topic configured', function (): void {
    [$organization, $event] = producerEnvironment(['lago.kafka.raw_events_topic' => null]);
    $transport = new ProducerTransportSpy;

    (new KafkaProducerService([$event], $organization, $transport))->execute();

    expect($transport->produced)->toBe([]);
});
