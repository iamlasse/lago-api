-- ClickHouse schema for the Lago Laravel port — the events_enriched /
-- events_raw tables consumed by App\Services\Events\Stores\ClickHouseStore
-- and the events processor.
--
-- Port of Rails' db/clickhouse_migrate for the events tables:
--   20231024084411_create_events_raw.rb
--   20240705080709_create_events_enriched.rb
--   20240705084952_create_events_enriched_queue.rb   (Kafka — dev only)
--   20240705085501_create_events_enriched_mv.rb      (Kafka — dev only)
--   20260727090000_set_events_enriched_at_default_to_now64.rb
--   20260916120000_add_attribution_labels_to_events_enriched.rb
--
-- DOCUMENTED, NOT AUTO-RUN: nothing in the Laravel port provisions
-- ClickHouse automatically. To spin the dev container (image matches the
-- Rails docker-compose.dev.yml `clickhouse` service):
--
--   docker run -d --name lago-laravel-clickhouse --rm -p 8123:8123 \
--     -e CLICKHOUSE_USER=default -e CLICKHOUSE_PASSWORD=default \
--     clickhouse/clickhouse-server:26.2-alpine
--
--   docker exec -i lago-laravel-clickhouse clickhouse-client \
--     --multiquery < scripts/clickhouse/init.sql
--
-- and point the app at it with
--   LAGO_CLICKHOUSE_ENABLED=true
--   LAGO_CLICKHOUSE_HOST=127.0.0.1 LAGO_CLICKHOUSE_PORT=8123
--   LAGO_CLICKHOUSE_DATABASE=default
--   LAGO_CLICKHOUSE_USERNAME=default LAGO_CLICKHOUSE_PASSWORD=default
--
-- The queue table + materialized view require the Kafka broker
-- (LAGO_KAFKA_BOOTSTRAP_SERVERS / LAGO_KAFKA_ENRICHED_EVENTS_TOPIC /
-- LAGO_KAFKA_CLICKHOUSE_CONSUMER_GROUP) and are therefore NOT created here
-- — in a bare dev container insert into events_enriched directly.

-- events_enriched — the deduplication read path of the store. The
-- ReplacingMergeTree(timestamp) collapses rows sharing the ORDER BY key at
-- read time (with FINAL), keeping the row from the most recent part —
-- that is the semantics the ported deduplicated_events_sql relies on.
CREATE TABLE IF NOT EXISTS events_enriched
(
    organization_id String,
    external_subscription_id String,
    code String,
    timestamp DateTime64(3),
    transaction_id String,
    properties Map(String, String),
    sorted_properties Map(String, String) DEFAULT mapSort(properties),
    value Nullable(String),
    decimal_value Decimal(38, 26) DEFAULT toDecimal128OrZero(value, 26),
    enriched_at DateTime64(3) DEFAULT now64(3),
    precise_total_amount_cents Nullable(Decimal(40, 15)),
    attribution_labels Map(String, String)
)
ENGINE = ReplacingMergeTree(timestamp)
PRIMARY KEY (organization_id, code, external_subscription_id, toDate(timestamp))
ORDER BY (organization_id, code, external_subscription_id, toDate(timestamp), timestamp, transaction_id);

-- events_raw — the ingest-side table (MergeTree, no deduplication on read).
CREATE TABLE IF NOT EXISTS events_raw
(
    organization_id String,
    external_customer_id String,
    external_subscription_id String,
    transaction_id String,
    timestamp DateTime64(3),
    code String,
    properties Map(String, String),
    precise_total_amount_cents Nullable(Decimal(40, 15)),
    ingested_at DateTime64(3)
)
ENGINE = MergeTree
ORDER BY (organization_id, external_subscription_id, code, transaction_id, timestamp);
