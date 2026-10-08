<?php

declare(strict_types=1);

namespace App\Services\Kafka\Transports;

use RdKafka\Conf;
use RdKafka\Producer;
use Illuminate\Support\Facades\Log;
use App\Services\Kafka\Contracts\KafkaProducerTransport;

/**
 * php-rdkafka producer — the port of `Karafka.producer` on the write
 * side (librdkafka behind the rdkafka extension).
 *
 * UNTESTED: the port's containers do not ship the extension (no composer
 * changes allowed, php:8.4 does not bundle rdkafka). Loaded only when
 * `extension_loaded('rdkafka')` — see KafkaConsumeCommand::transport().
 * TODO(port): integration coverage against a real broker.
 */
final class RdKafkaProducerTransport implements KafkaProducerTransport
{
    private ?Producer $producer = null;

    /** @param  array<string, string>  $config  librdkafka properties (bootstrap.servers, security.*, sasl.*) */
    public function __construct(private readonly array $config = []) {}

    public static function available(): bool
    {
        return extension_loaded('rdkafka');
    }

    public function produce(string $topic, string $payload): void
    {
        // RdKafka\Producer::newTopic caches per name; one producer handle
        // for the process lifetime, like Karafka's.
        $topicHandle = $this->producer()->newTopic($topic);
        $topicHandle->produce(RD_KAFKA_PARTITION_UA, 0, $payload);
    }

    private function producer(): Producer
    {
        if ($this->producer instanceof Producer) {
            return $this->producer;
        }

        $conf = new Conf;

        foreach ($this->config as $name => $value) {
            $conf->set($name, $value);
        }

        // Rails subscribes to the producer's error events and logs them;
        // librdkafka surfaces them on the log callback.
        $conf->setErrorCb(function ($kafka, $err, $reason): void {
            Log::error('[kafka] producer error: '.$reason);
        });

        $this->producer = new Producer($conf);

        // Flush the librdkafka queue in the background — produce_many_async
        // never blocks the caller.
        $this->producer->poll(0);

        return $this->producer;
    }
}
