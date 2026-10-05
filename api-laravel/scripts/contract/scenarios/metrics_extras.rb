# frozen_string_literal: true

# Contract scenario: metrics_extras — the billable_metrics pieces the M1 gate
# lists as contract-untested:
#   * POST /api/v1/billable_metrics/evaluate_expression — the sandboxed
#     expression DSL: success ("round(event.properties.value * …)"),
#     `event.timestamp` falling back to the CURRENT (frozen) time, the blank
#     expression 422 (value_is_mandatory), the unparseable expression 422
#     (invalid_expression), and the runtime-evaluation failure 422
#     (invalid_event);
#   * PATCH /api/v1/billable_metrics/:code — the PATCH-verb update, setting
#     expression + rounding_function/rounding_precision (allowed: the metric
#     is attached to NO plan, so every field is editable);
#   * PUT with `filters` — the filter batch upsert (CreateOrUpdateBatchService)
#     replacing the values of a seeded key and adding a new key.
#
# Run via scripts/contract/capture.sh metrics_extras — see auth_org.rb for
# the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000091"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000092"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000ae"
CAPTURED_AT = Time.utc(2025, 6, 12, 15, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {}, at: CAPTURED_AT)
  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: path,
    headers: headers, body: body, required_headers: required_headers, at: at.iso8601
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
    name: "Contract Metrics Extras Org",
    slug: "contract-metrics-extras-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Metrics Extras Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Seeded GPU Hours",
    code: "seeded_gpu_hours",
    description: "seeded sum metric",
    field_name: "gpu_hours",
    recurring: false
  ).tap do |metric|
    FactoryBot.create(
      :billable_metric_filter,
      billable_metric: metric,
      key: "region",
      values: ["us"]
    )
  end
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

# 1. evaluate_expression success — round(value * units), properties are
#    stringified by the service before evaluation (golden: 21.0).
capture!(:post, "/api/v1/billable_metrics/evaluate_expression",
  headers: json,
  body: {
    expression: "round(event.properties.value * event.properties.units)",
    event: {
      code: "test_code",
      timestamp: CAPTURED_AT.to_i,
      properties: {value: "10.4", units: "2"}
    }
  })

# 2. `event.timestamp` with NO timestamp in the event — falls back to
#    Time.current, which is the FROZEN capture instant (deterministic).
capture!(:post, "/api/v1/billable_metrics/evaluate_expression",
  headers: json,
  body: {
    expression: "event.timestamp",
    event: {code: "test_code", properties: {}}
  })

# 3. blank expression — value_is_mandatory.
capture!(:post, "/api/v1/billable_metrics/evaluate_expression",
  headers: json,
  body: {expression: "", event: {code: "test_code", properties: {}}})

# 4. unparseable expression — invalid_expression.
capture!(:post, "/api/v1/billable_metrics/evaluate_expression",
  headers: json,
  body: {expression: "invalid_expression", event: {code: "test_code", properties: {}}})

# 5. parses but RAISES at evaluation time — invalid_event (the service
#    rescues RuntimeError and blames the event, not the expression).
capture!(:post, "/api/v1/billable_metrics/evaluate_expression",
  headers: json,
  body: {
    expression: "round(event.properties.value)",
    event: {
      code: "test_code",
      timestamp: CAPTURED_AT.to_i,
      properties: {value: "invalid_value"}
    }
  })

# 6. PATCH-verb update on an UNATTACHED metric — expression + rounding
#    fields are editable (name/description only when attached to a plan).
capture!(:patch, "/api/v1/billable_metrics/seeded_gpu_hours",
  headers: json,
  body: {billable_metric: {
    description: "patched by the capture",
    expression: "round(event.properties.gpu_hours * event.properties.multiplier)",
    rounding_function: "round",
    rounding_precision: 2
  }})

# 7. PUT with `filters` — the batch upsert: existing key's values replaced,
#    a NEW key appended.
capture!(:put, "/api/v1/billable_metrics/seeded_gpu_hours",
  headers: json,
  body: {billable_metric: {
    filters: [
      {key: "region", values: ["us", "eu"]},
      {key: "tier", values: ["gold"]}
    ]
  }})

# 8. show — both updates reflected in one read.
capture!(:get, "/api/v1/billable_metrics/seeded_gpu_hours", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "metrics_extras capture complete: #{MANIFEST[:requests].length} requests"
