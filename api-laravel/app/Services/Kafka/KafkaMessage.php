<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use JsonException;

/**
 * A decoded Kafka message as the consumers see it — the port's stand-in
 * for Karafka's message object (topic + raw payload; the payload is
 * decoded once for the handlers).
 *
 * A null payload is a tombstone (key recorded, value deleted) — the
 * upsert-format triggers topic emits them for retractions, and the wallet
 * refresh consumer counts them apart from the triggers.
 */
final class KafkaMessage
{
    public function __construct(
        public readonly string $topic,
        public readonly ?string $payload,
        public readonly ?string $key = null,
    ) {}

    /** True when the message is a tombstone (no value). */
    public function isTombstone(): bool
    {
        return $this->payload === null;
    }

    /**
     * The decoded JSON payload, or null for a tombstone / undecodable
     * value. Karafka's JSON parser raises on garbage; a raise out of
     * #consume replays the batch, so an undecodable payload is surfaced
     * as null here and the callers decide (the wallet consumer drops it,
     * the charged-in-advance consumer skips it).
     */
    public function decode(): ?array
    {
        if ($this->payload === null) {
            return null;
        }

        try {
            $decoded = json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
