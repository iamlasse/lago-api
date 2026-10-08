<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use Illuminate\Support\Facades\Log;
use App\Jobs\Events\PayInAdvanceJob;
use App\Services\Events\CommonFactory;

/**
 * Port of Rails' EventsChargedInAdvanceConsumer
 * (app/consumers/events_charged_in_advance_consumer.rb) — the ClickHouse
 * dual-write path: the enriched-events pipeline emits one message per
 * pay-in-advance event on the charged-in-advance topic, and the consumer
 * re-dispatches the pay-in-advance job with the ClickHouse merge delay so
 * the enriched event has landed before the job reads the store.
 *
 * Laravel has no Karafka: the consumer logic lives here (one call per
 * topic batch, synthetic messages in the tests) and the transport is the
 * `kafka:consume` artisan command wired to php-rdkafka — see
 * app/Console/Commands/KafkaConsumeCommand.php.
 */
final class EventsChargedInAdvanceConsumer
{
    /**
     * Rails: `Events::Stores::ClickhouseStore::CLICKHOUSE_MERGE_DELAY` —
     * give ClickHouse time to consume the enriched event.
     */
    public const MERGE_DELAY_SECONDS = 15;

    /**
     * Port of `#consume` — one message per pay-in-advance event; each is
     * dispatched as its own job, delayed past the ClickHouse merge.
     *
     * @param  list<KafkaMessage>  $messages
     */
    public function consume(array $messages): void
    {
        foreach ($messages as $message) {
            $payload = $message->decode();

            if ($payload === null) {
                Log::warning('[kafka] charged-in-advance topic carried a non-JSON payload, skipping');

                continue;
            }

            PayInAdvanceJob::dispatch(CommonFactory::newInstanceFromHash($payload))
                ->delay(now()->addSeconds(self::MERGE_DELAY_SECONDS));
        }
    }
}
