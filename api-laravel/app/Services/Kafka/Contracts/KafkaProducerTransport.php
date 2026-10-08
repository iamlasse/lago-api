<?php

declare(strict_types=1);

namespace App\Services\Kafka\Contracts;

/**
 * The producer side of the Kafka seam behind
 * Events\KafkaProducerService — the port of
 * `Karafka.producer.produce_many_async`.
 */
interface KafkaProducerTransport
{
    /**
     * Fire-and-forget async produce (Rails: produce_many_async). Delivery
     * failures are surfaced by the transport itself (log/report), never by
     * blocking the caller — the API path must stay as fast as Rails'.
     */
    public function produce(string $topic, string $payload): void;
}
