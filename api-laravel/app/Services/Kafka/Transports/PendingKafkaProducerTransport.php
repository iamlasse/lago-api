<?php

declare(strict_types=1);

namespace App\Services\Kafka\Transports;

use Illuminate\Support\Facades\Log;
use App\Services\Kafka\Contracts\KafkaProducerTransport;

/**
 * The default KafkaProducerTransport until a Kafka client is available —
 * logs and drops. No composer changes are allowed in the port and the
 * php:8.4 images do not ship php-rdkafka, so this is the documented
 * transport decision (see DEPLOY.md, "Kafka"): deploy the API with the
 * php-rdkafka extension and the RdKafka transport takes over, or point
 * the deployment at a Kafka REST proxy.
 *
 * TODO(port): RdKafkaProducerTransport activation or a REST-proxy client.
 */
final class PendingKafkaProducerTransport implements KafkaProducerTransport
{
    public function produce(string $topic, string $payload): void
    {
        Log::warning(sprintf(
            '[kafka] raw event dropped: no Kafka transport configured (topic=%s, %d bytes). TODO(port): enable the php-rdkafka transport or wire a Kafka REST proxy.',
            $topic,
            mb_strlen($payload),
        ));
    }
}
