<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Services\Kafka\Contracts\KafkaProducerTransport;
use App\Services\Kafka\Transports\PendingKafkaProducerTransport;

/**
 * Shared plumbing of the log producers (App\Services\Logs\{ApiLog,
 * ActivityLog,SecurityLog}) — the port of Rails' KafkaProducer
 * (app/services/kafka_producer.rb) `produce_async` for the three log
 * topics, routed through the KafkaProducerTransport seam the Kafka/events
 * wiring slice owns (Events\KafkaProducerService uses the same contract).
 *
 * Rails partitions by the "{organization_id}--{entity_id}" key; the ported
 * transport contract has no key parameter yet
 * (TODO(port): partition_key support on the transport), so payloads publish
 * round-robin until then.
 */
final class Kafka
{
    /** Rails: ENV["LAGO_KAFKA_BOOTSTRAP_SERVERS"].present? */
    public static function configured(): bool
    {
        return (bool) config('lago.kafka.bootstrap_servers');
    }

    /**
     * KafkaProducer.produce_async — fire-and-forget. Returns true when the
     * payload was dispatched (or dropped by the Pending transport), false
     * when Kafka is not configured.
     */
    public static function produceAsync(string $topic, string $payload): bool
    {
        if (! self::configured()) {
            return false;
        }

        self::transport()->produce($topic, $payload);

        return true;
    }

    private static function transport(): KafkaProducerTransport
    {
        // A container binding (registered by the Kafka wiring slice) wins;
        // otherwise the documented Pending transport (logs + drops).
        if (app()->bound(KafkaProducerTransport::class)) {
            return app(KafkaProducerTransport::class);
        }

        return new PendingKafkaProducerTransport;
    }
}
