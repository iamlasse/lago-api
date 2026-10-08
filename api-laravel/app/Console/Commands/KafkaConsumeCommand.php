<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Kafka\KafkaMessage;
use App\Services\Kafka\WalletRefreshTriggersConsumer;
use App\Services\Kafka\EventsChargedInAdvanceConsumer;
use App\Services\Kafka\Contracts\KafkaConsumerTransport;
use App\Services\Kafka\Transports\RdKafkaConsumerTransport;

/**
 * The port of Karafka's consumer process (karafka.rb + app/consumers/*)
 * as a long-running artisan command — the transport decision is in
 * DEPLOY.md ("Kafka"): php-rdkafka is NOT in the port's containers (no
 * composer changes, php:8.4 does not bundle the extension), so the
 * command refuses to run without it until the deployment adds the
 * extension (RdKafkaConsumerTransport) or a Kafka REST proxy adapter is
 * written. The consumer LOGIC behind each topic is transport-independent
 * and unit-tested with synthetic messages — see
 * app/Services/Kafka/*Consumer.php.
 */
final class KafkaConsumeCommand extends Command
{
    /** karafka.rb consumer groups, keyed by the topic env name. */
    private const ROUTES = [
        'lago.kafka.events_charged_in_advance_topic' => EventsChargedInAdvanceConsumer::class,
        'lago.kafka.realtime_usage_triggers_topic' => WalletRefreshTriggersConsumer::class,
    ];

    private const GROUP_PREFIXES = [
        EventsChargedInAdvanceConsumer::class => 'lago_events_charged_in_advance_consumer',
        WalletRefreshTriggersConsumer::class => 'lago_wallet_refresh_triggers_consumer',
    ];

    protected $signature = 'kafka:consume
        {--once : Route a single batch, then exit (smoke-testing the wiring)}
        {--max-messages=10000 : Batch size cap (karafka.rb: max_messages)}
        {--max-wait-ms=1000 : How long a poll may sit on a sparse batch}';

    protected $description = 'Consume the Lago Kafka topics (charged-in-advance events, realtime wallet refresh triggers)';

    public function handle(): int
    {
        $transport = $this->transport();

        if ($transport === null) {
            return self::FAILURE;
        }

        // karafka.rb draws each route only when its topic env is present.
        $routes = [];
        foreach (self::ROUTES as $configKey => $consumerClass) {
            $topic = config($configKey);

            if (is_string($topic) && $topic !== '') {
                $routes[$topic] = $consumerClass;
            }
        }

        if ($routes === []) {
            $this->info('No Kafka topics configured (LAGO_KAFKA_*_TOPIC env) — nothing to consume.');

            return self::SUCCESS;
        }

        $transport->subscribe($this->groupId(array_values($routes)), array_keys($routes));

        $maxMessages = max(1, (int) $this->option('max-messages'));
        $maxWaitMs = max(1, (int) $this->option('max-wait-ms'));

        do {
            $messages = $transport->consumeBatch($maxMessages, $maxWaitMs);

            foreach ($this->batchesPerTopic($messages) as $topic => $topicMessages) {
                $consumerClass = $routes[$topic] ?? null;

                if ($consumerClass === null) {
                    continue;
                }

                // One topic batch per consumer call — the same unit
                // Karafka's #consume receives.
                app($consumerClass)->consume($topicMessages);
            }

            $transport->commit();
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * The transport decision: the rdkafka extension when present, otherwise
     * a documented refusal (TODO(port): REST-proxy adapter).
     */
    private function transport(): ?KafkaConsumerTransport
    {
        $bootstrapServers = config('lago.kafka.bootstrap_servers');

        if (! is_string($bootstrapServers) || $bootstrapServers === '') {
            $this->info('LAGO_KAFKA_BOOTSTRAP_SERVERS is not set — Kafka consuming is disabled.');

            return null;
        }

        if (! RdKafkaConsumerTransport::available()) {
            $this->error(sprintf(
                "The php-rdkafka extension is not loaded — the port's containers do not ship it.\n".
                "The Kafka consumer logic itself is ported and unit-tested (app/Services/Kafka/*Consumer.php);\n".
                'this process needs the transport: install php-rdkafka (see DEPLOY.md, "Kafka")'.
                " or wire a Kafka REST proxy adapter (TODO(port)).\n".
                'Topics: %s / %s',
                (string) config('lago.kafka.events_charged_in_advance_topic'),
                (string) config('lago.kafka.realtime_usage_triggers_topic'),
            ));

            Log::warning('[kafka] kafka:consume refused: php-rdkafka extension missing');

            return null;
        }

        $extra = array_filter([
            'security.protocol' => config('lago.kafka.security_protocol'),
            'sasl.mechanisms' => config('lago.kafka.sasl_mechanisms'),
            'sasl.username' => config('lago.kafka.sasl_username'),
            'sasl.password' => config('lago.kafka.sasl_password'),
        ], fn ($v): bool => is_string($v) && $v !== '');

        return new RdKafkaConsumerTransport($bootstrapServers, $extra);
    }

    /**
     * One librdkafka consumer cannot hold two group ids, and the Karafka
     * routes use one group per topic; when both topics are routed here the
     * combined id keeps the routing honest about being one process.
     */
    private function groupId(array $consumerClasses): string
    {
        $prefixes = array_map(fn (string $class): string => self::GROUP_PREFIXES[$class] ?? $class, $consumerClasses);
        sort($prefixes);

        return implode('+', $prefixes);
    }

    /**
     * @param  list<KafkaMessage>  $messages
     * @return array<string, list<KafkaMessage>>
     */
    private function batchesPerTopic(array $messages): array
    {
        $batches = [];

        foreach ($messages as $message) {
            $batches[$message->topic][] = $message;
        }

        return $batches;
    }
}
