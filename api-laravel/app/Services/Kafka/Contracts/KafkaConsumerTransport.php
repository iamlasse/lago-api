<?php

declare(strict_types=1);

namespace App\Services\Kafka\Contracts;

use App\Services\Kafka\KafkaMessage;

/**
 * The consumer side of the Kafka seam behind the `kafka:consume` command
 * — the port of Karafka's consumer loop (subscribe, batch poll, commit).
 */
interface KafkaConsumerTransport
{
    /**
     * Subscribe the group to every routed topic (Karafka: the consumer
     * group's route).
     *
     * @param  list<string>  $topics
     */
    public function subscribe(string $groupId, array $topics): void;

    /**
     * One batch off the subscription — Karafka's `#consume` unit.
     * Returns [] when the poll window expired with nothing to do.
     *
     * @return list<KafkaMessage>
     */
    public function consumeBatch(int $maxMessages, int $maxWaitMs): array;

    /** Commit the offsets of the consumed batch (Karafka does this per batch). */
    public function commit(): void;
}
