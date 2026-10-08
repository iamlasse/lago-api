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

-- ============================================================================
-- Log surfaces (App\Services\ClickHouse\Logs) — activity_logs, api_logs,
-- security_logs.
--
-- Port of Rails' db/clickhouse_migrate for the log tables:
--   20250416103745_create_activity_logs.rb
--   20250605162945_create_api_logs.rb
--   20260202135506_create_security_logs.rb
--   20260710115832_add_activity_logs_external_customer_id_index.rb
--   20260710124100_add_activity_logs_external_subscription_id_index.rb
--   20261001175846_add_patch_to_api_logs_http_method.rb  (final enum state)
--
-- The Kafka queue tables (*_queue) + materialized views (*_mv) that feed
-- these tables in Rails (producers: app/services/utils/{api,activity,
-- security}_log.rb) require the Kafka broker (LAGO_KAFKA_BOOTSTRAP_SERVERS /
-- LAGO_KAFKA_{API,ACTIVITY,SECURITY}_LOGS_TOPIC /
-- LAGO_KAFKA_CLICKHOUSE_CONSUMER_GROUP) and are NOT created here — in a bare
-- dev container insert into the log tables directly.
--
-- NOTE: the Rails CLOUD variant (db/clickhouse_migrate/cloud/03) uses
-- SharedMergeTree and a different activity_logs key
-- (organization_id, activity_type, activity_id, logged_at); the self-hosted
-- OSS migrations below keep ReplacingMergeTree(logged_at) with the
-- (organization_id, activity_id, logged_at) key.

-- activity_logs — audit trail of resource lifecycle events.
-- ReplacingMergeTree(logged_at) collapses duplicate (organization_id,
-- activity_id, logged_at) rows at read time (FINAL), keeping the newest part.
CREATE TABLE IF NOT EXISTS activity_logs
(
    organization_id String,
    user_id Nullable(String),
    api_key_id Nullable(String),
    external_customer_id Nullable(String),
    external_subscription_id Nullable(String),
    activity_id String,
    activity_type String,
    activity_source Enum8('api' = 1, 'front' = 2, 'system' = 3),
    activity_object Map(String, Nullable(String)),
    activity_object_changes Map(String, Nullable(String)),
    resource_id String,
    resource_type String,
    logged_at DateTime64(3),
    created_at DateTime64(3),
    INDEX idx_external_customer_id external_customer_id TYPE bloom_filter(0.001) GRANULARITY 1,
    INDEX idx_external_subscription_id external_subscription_id TYPE bloom_filter(0.001) GRANULARITY 1
)
ENGINE = ReplacingMergeTree(logged_at)
PRIMARY KEY (organization_id, activity_id, logged_at)
ORDER BY (organization_id, activity_id, logged_at);

-- api_logs — request/response trail of the public REST API (non-GET writes).
-- Plain MergeTree: every request is kept (request_id is the unique key).
CREATE TABLE IF NOT EXISTS api_logs
(
    request_id String,
    organization_id String,
    api_key_id String,
    api_version String,
    client String,
    request_body Map(String, String),
    request_response Map(String, Nullable(String)),
    request_path String,
    request_origin String,
    http_method Enum8('get' = 1, 'post' = 2, 'put' = 3, 'delete' = 4, 'patch' = 5),
    http_status UInt32,
    logged_at DateTime64(3),
    created_at DateTime64(3)
)
ENGINE = MergeTree
PRIMARY KEY (organization_id, api_key_id, request_id, logged_at)
ORDER BY (organization_id, api_key_id, request_id, logged_at);

-- security_logs — user / system configuration change trail (api keys, roles,
-- webhooks, exports, integrations, billing entities).
-- ReplacingMergeTree(logged_at), same read semantics as activity_logs.
CREATE TABLE IF NOT EXISTS security_logs
(
    organization_id String,
    user_id Nullable(String),
    api_key_id Nullable(String),
    log_id String,
    log_type String,
    log_event String,
    device_info Map(String, Nullable(String)),
    resources Map(String, Nullable(String)),
    logged_at DateTime64(3),
    created_at DateTime64(3)
)
ENGINE = ReplacingMergeTree(logged_at)
PRIMARY KEY (organization_id, log_id, logged_at)
ORDER BY (organization_id, log_id, logged_at);
