# frozen_string_literal: true

# Contract scenario: events_ingestion — the events endpoint: plain create,
# duplicate transaction_id (422 value_already_exist), invalid create
# (missing code), an event against an EXPRESSION billable metric (the
# evaluated value is persisted into the event's properties — the replay's
# expression engine has to match), an expression event missing its input
# property (422 expression_evaluation_failed), a mixed batch (one invalid
# event fails the WHOLE batch with per-index errors), a fully valid batch,
# show by transaction_id, and the index (timestamp DESC — every event
# carries an explicit, distinct timestamp so the ordering is runtime-stable).
#
# Run via scripts/contract/capture.sh events_ingestion — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000131"
SUM_METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000132"
EXPR_METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000133"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000001ee"
CUSTOMER_EXTERNAL_ID = "events-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "events-sub-1"
CAPTURED_AT = Time.utc(2025, 6, 9, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

# Explicit event timestamps (epoch SECONDS) — the index sorts timestamp DESC,
# so distinct values keep the golden order runtime-stable. All inside the
# frozen week, strictly before the captured instant.
TS1 = 1_749_451_200 # 2025-06-12T04:00:00Z — see note: events may carry
                    # timestamps after ingest; ordering is what matters.
TS2 = TS1 + 100
TS3 = TS1 + 200
TS4 = TS1 + 300
TS5 = TS1 + 400
TS6 = TS1 + 500

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {}, at: CAPTURED_AT, tokens: {})
  manifest_path = path.dup
  manifest_body = body.nil? ? nil : JSON.generate(body)
  tokens.each do |real, token|
    manifest_path = manifest_path.gsub(real, token)
    manifest_body = manifest_body.gsub(real, token) unless manifest_body.nil?
  end

  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: manifest_path,
    headers: headers, body: manifest_body.nil? ? nil : JSON.parse(manifest_body),
    required_headers: required_headers, at: at.iso8601
  }

  kwargs = {headers: headers}
  kwargs[:params] = body.nil? ? nil : JSON.generate(body)
  travel_to(at) { SESSION.public_send(verb, path, **kwargs) }

  File.write(
    File.join(GOLDENS, "#{MANIFEST[:requests].length}.json"),
    JSON.pretty_generate(JSON.parse(SESSION.response.body))
  )
  warn "captured ##{MANIFEST[:requests].length} #{verb.to_s.upcase} #{path} -> #{SESSION.response.status}"
end

extend ActiveSupport::Testing::TimeHelpers

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Events Org",
    slug: "contract-events-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Events Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  # The events' dedup uniqueness is (organization_id, external_subscription_id,
  # transaction_id) — with a NULL external_subscription_id Postgres treats
  # duplicates as distinct, so every event below addresses a SEEDED
  # subscription to actually engage the constraint (the duplicate case is
  # the point of requests #2 and #6).
  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Events Customer Co.",
    firstname: "Eve",
    lastname: "Nts",
    email: "events-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "6 Rue des Evenements",
    zipcode: "75009",
    state: "IDF"
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Events Plan",
    code: "events-plan",
    interval: "monthly",
    amount_cents: 1900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan,
    external_id: SUBSCRIPTION_EXTERNAL_ID,
    name: "Events Sub",
    status: "active",
    subscription_at: Time.utc(2025, 5, 15, 0, 0, 0),
    started_at: Time.utc(2025, 5, 15, 0, 0, 0)
  )

  FactoryBot.create(
    :sum_billable_metric,
    id: SUM_METRIC_ID,
    organization: organization,
    name: "API Calls",
    code: "api_calls",
    description: "seeded sum metric",
    field_name: "value",
    recurring: false
  )

  # The expression metric: every ingested event gets `value * 2` evaluated
  # and the result written into properties["total"] before the row is saved.
  FactoryBot.create(
    :billable_metric,
    id: EXPR_METRIC_ID,
    organization: organization,
    name: "Computed Total",
    code: "computed_total",
    description: "seeded expression metric",
    aggregation_type: "sum_agg",
    field_name: "total",
    expression: "event.properties.value * 2",
    recurring: false
  )
end

travel_to(CAPTURED_AT) do
  # ---- SEED STATE IS FROZEN HERE ------------------------------------------
  dump = IO.popen(
    ["pg_dump", "--data-only", "--inserts",
      "--exclude-table=ar_internal_metadata",
      "--exclude-table=schema_migrations",
      "--exclude-schema=partman",
      ENV.fetch("DATABASE_URL")],
    &:read
  )
  abort "pg_dump failed (empty output)" if dump.strip.empty?

  dump = dump.lines
    .reject { |line| line.start_with?("\\restrict", "\\unrestrict") }
    .reject { |line| line.start_with?("SET transaction_timeout") }
    .join

  File.write(File.join(GOLDENS, "fixture.sql"), dump)
  warn "seed state dumped to fixture.sql"
end

auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}
  json = auth.merge("Content-Type" => "application/json")

  # 1. plain create against the sum metric.
  capture!(:post, "/api/v1/events",
    headers: json,
    body: {event: {
      transaction_id: "evt-txn-1",
      code: "api_calls",
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      timestamp: TS2,
      properties: {"value" => 15}
    }})

  # 2. duplicate transaction_id — 422 value_already_exist.
  capture!(:post, "/api/v1/events",
    headers: json,
    body: {event: {
      transaction_id: "evt-txn-1",
      code: "api_calls",
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      timestamp: TS3,
      properties: {"value" => 1}
    }})

  # 3. missing code — the validation-error envelope.
  capture!(:post, "/api/v1/events",
    headers: json,
    body: {event: {
      transaction_id: "evt-txn-bad",
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      timestamp: TS3,
      properties: {}
    }})

  # 4. expression metric — properties["total"] comes from evaluating
  #    `value * 2` at ingest (21 * 2 = 42 in the golden).
  capture!(:post, "/api/v1/events",
    headers: json,
    body: {event: {
      transaction_id: "evt-txn-2",
      code: "computed_total",
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      timestamp: TS4,
      properties: {"value" => 21}
    }})

  # 5. expression metric WITHOUT its input property — 422
  #    expression_evaluation_failed (the parser cannot resolve `value`).
  capture!(:post, "/api/v1/events",
    headers: json,
    body: {event: {
      transaction_id: "evt-txn-3",
      code: "computed_total",
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      timestamp: TS5,
      properties: {"other" => 1}
    }})

  # 6. MIXED batch — one duplicate among valid events fails the WHOLE batch
  #    with the per-index error map (Events::CreateBatchService validates
  #    every entry before writing any).
  capture!(:post, "/api/v1/events/batch",
    headers: json,
    body: {events: [
      {transaction_id: "evt-batch-a", code: "api_calls", external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
       timestamp: TS5, properties: {"value" => 3}},
      {transaction_id: "evt-txn-1", code: "api_calls", external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
       timestamp: TS5, properties: {"value" => 4}},
      {transaction_id: "evt-batch-b", code: "api_calls", external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
       timestamp: TS5, properties: {"value" => 5}}
    ]})

  # 7. valid batch — two events, distinct timestamps.
  capture!(:post, "/api/v1/events/batch",
    headers: json,
    body: {events: [
      {transaction_id: "evt-batch-c", code: "api_calls", external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
       timestamp: TS6, properties: {"value" => 7}},
      {transaction_id: "evt-batch-d", code: "computed_total", external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
       timestamp: TS6 + 1, properties: {"value" => 5}}
    ]})

  # CAPTURE-ARTIFACT FIX: the batch ingest writes its rows through a bulk
  # INSERT whose timestamps come from the DATABASE clock (insert_all —
  # travel_to cannot stub it), so the golden would bake in the capture DAY's
  # wall clock and be unreproducible by ANY replay. The batch events'
  # created_at/updated_at are runtime state, not contract, so both batch
  # writes are re-stamped to the frozen instant — the same value the single
  # events carry (Eloquent path, stubbed clock) and the value the Laravel
  # replay mints under its frozen clock.
  Event.where(transaction_id: %w[evt-batch-a evt-batch-b evt-batch-c evt-batch-d])
    .update_all(created_at: CAPTURED_AT, updated_at: CAPTURED_AT)

  # Same artifact in the RESPONSE golden itself: the batch serializer echoed
  # the wall-clock created_at before the re-stamp above, so golden 7.json is
  # aligned with it (the replay's serializer runs under the frozen clock —
  # Laravel answers exactly what this re-stamped golden says).
  golden7_path = File.join(GOLDENS, "7.json")
  golden7 = JSON.parse(File.read(golden7_path))
  golden7["events"].each do |event|
    event["created_at"] = CAPTURED_AT.iso8601
    event["updated_at"] = CAPTURED_AT.iso8601
  end
  File.write(golden7_path, JSON.pretty_generate(golden7))

  # 8. show by transaction_id (deterministic — not a minted id).
  capture!(:get, "/api/v1/events/evt-txn-2", headers: auth)

  # 9. index — the four stored events (1, 4, 7c, 7d; duplicates/failed never
  #    persisted), newest timestamp first.
  capture!(:get, "/api/v1/events", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "events_ingestion capture complete: #{MANIFEST[:requests].length} requests"
