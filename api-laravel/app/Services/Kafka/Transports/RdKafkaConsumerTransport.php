<?php

declare(strict_types=1);

namespace App\Services\Kafka\Transports;

use RdKafka\Conf;
use LogicException;
use RuntimeException;
use RdKafka\KafkaConsumer;
use Illuminate\Support\Facades\Log;
use App\Services\Kafka\KafkaMessage;
use App\Services\Kafka\Contracts\KafkaConsumerTransport;

/**
 * php-rdkafka consumer — the port of Karafka's consumer loop on the read
 * side (librdkafka behind the rdkafka extension).
 *
 * UNTESTED: the port's containers do not ship the extension (no composer
 * changes allowed, php:8.4 does not bundle rdkafka). Loaded only when
 * `extension_loaded('rdkafka')` — see KafkaConsumeCommand::transport().
 * TODO(port): integration coverage against a real broker.
 */
final class RdKafkaConsumerTransport implements KafkaConsumerTransport
{
    private ?KafkaConsumer $consumer = null;

    /** @param  array<string, string>  $config  extra librdkafka properties (security.*, sasl.*) */
    public function __construct(
        private readonly string $bootstrapServers,
        private readonly array $config = [],
    ) {}

    public static function available(): bool
    {
        return extension_loaded('rdkafka');
    }

    public function subscribe(string $groupId, array $topics): void
    {
        $conf = new Conf;

        $conf->set('bootstrap.servers', $this->bootstrapServers);
        $conf->set('group.id', $groupId);
        $conf->set('enable.auto.commit', 'false');

        foreach ($this->config as $name => $value) {
            $conf->set($name, $value);
        }

        $conf->setErrorCb(function ($kafka, $err, $reason): void {
            Log::error('[kafka] consumer error: '.$reason);
        });

        $this->consumer = new KafkaConsumer($conf);
        $this->consumer->subscribe($topics);
    }

    public function consumeBatch(int $maxMessages, int $maxWaitMs): array
    {
        $consumer = $this->consumer
            ?? throw new LogicException('subscribe() must run before consumeBatch()');

        $messages = [];
        $deadline = now()->addMilliseconds($maxWaitMs);

        while (count($messages) < $maxMessages) {
            $message = $consumer->consume(100);

            match ($message->err) {
                RD_KAFKA_RESP_ERR_NO_ERROR => $messages[] = new KafkaMessage(
                    topic: $message->topic_name,
                    payload: $message->payload,
                    key: $message->key,
                ),
                // Nothing available in this poll window — idle briefly like
                // Karafka's max_wait_time so a quiet topic does not spin.
                RD_KAFKA_RESP_ERR__PARTITION_EOF,
                RD_KAFKA_RESP_ERR__TIMED_OUT,
                RD_KAFKA_RESP_ERR__NO_MORE_MESSAGES => usleep(50_000),
                default => throw new RuntimeException('kafka consume failed: '.$message->errstr()),
            };

            if (now()->gt($deadline)) {
                break;
            }
        }

        return $messages;
    }

    public function commit(): void
    {
        // Karafka commits the offsets of the consumed batch; async keeps
        // the loop from blocking on the broker.
        $this->consumer?->commitAsync();
    }
}
