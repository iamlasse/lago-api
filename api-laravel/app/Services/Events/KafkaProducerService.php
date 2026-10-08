<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Organization;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Kafka\Contracts\KafkaProducerTransport;
use App\Services\Kafka\Transports\PendingKafkaProducerTransport;

/**
 * Port of Rails' Events::KafkaProducerService
 * (app/services/events/kafka_producer_service.rb) — publishes raw events
 * to LAGO_KAFKA_RAW_EVENTS_TOPIC for the ClickHouse dual-write pipeline.
 *
 * Rail: `Karafka.producer.produce_many_async` (fire-and-forget; delivery
 * errors surface through the producer's error monitor). Port: the
 * KafkaProducerTransport seam. No composer packages are allowed, so the
 * default transport is Pending (logs + drops, TODO(port) below); if the
 * php-rdkafka extension is present the RdKafka transport takes over —
 * see app/Services/Kafka/Transports/.
 */
class KafkaProducerService extends BaseService
{
    /** Rails: EVENT_SOURCE = "http_ruby" — kept byte-identical (drop-in API container). */
    public const EVENT_SOURCE = 'http_ruby';

    public function __construct(
        /** @param list<Event> $events */
        private readonly array $events,
        private readonly Organization $organization,
        private readonly KafkaProducerTransport $transport = new PendingKafkaProducerTransport,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult();

        if (config('lago.kafka.bootstrap_servers') === null || config('lago.kafka.bootstrap_servers') === '') {
            return $result;
        }

        if (config('lago.kafka.raw_events_topic') === null || config('lago.kafka.raw_events_topic') === '') {
            return $result;
        }

        foreach ($this->events as $event) {
            $this->transport->produce(
                topic: (string) config('lago.kafka.raw_events_topic'),
                payload: $this->buildPayload($event),
            );
        }

        return $result;
    }

    // -- private ---------------------------------------------------------------

    private function buildPayload(Event $event): string
    {
        $timestamp = $event->timestamp;

        // NOTE: Removes trailing 'Z' to allow clickhouse parsing (Rails:
        // `event.timestamp.to_f.to_s` — fractional seconds, trailing zeros
        // collapsed, "…000.0" on a whole second).
        if ($timestamp === null) {
            $timestampValue = '0.0';
        } else {
            $fraction = mb_rtrim((string) $timestamp->format('u'), '0');
            $timestampValue = $timestamp->format('U').($fraction === '' ? '.0' : '.'.$fraction);
        }

        $preciseAmount = $event->precise_total_amount_cents;

        // NOTE: Default value to 0.0 is required for clickhouse parsing.
        $preciseValue = ($preciseAmount !== null && $preciseAmount !== '')
            ? (string) $preciseAmount
            : '0.0';

        return json_encode([
            'organization_id' => $this->organization->id,
            'external_customer_id' => $event->external_customer_id,
            'external_subscription_id' => $event->external_subscription_id,
            'transaction_id' => $event->transaction_id,
            'timestamp' => $timestampValue,
            'code' => $event->code,
            'precise_total_amount_cents' => $preciseValue,
            'properties' => $event->properties,
            // Rails: Time.zone.now.iso8601(3)[...-1] — millisecond precision,
            // trailing 'Z' dropped for ClickHouse.
            'ingested_at' => now()->format('Y-m-d\TH:i:s.v'),
            'source' => self::EVENT_SOURCE,
            'source_metadata' => [
                'api_post_processed' => ! $this->organization->clickhouseEventsStore(),
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
