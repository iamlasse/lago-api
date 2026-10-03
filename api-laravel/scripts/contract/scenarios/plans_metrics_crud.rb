# frozen_string_literal: true

# Contract scenario: plans_metrics_crud — billable metrics CRUD + plans CRUD
# with nested charges (standard, graduated, filtered) + the charge-filters
# index + the attach/unattach error envelope on metric destroy.
#
# Run via scripts/contract/capture.sh plans_metrics_crud — see auth_org.rb
# for the general mechanics. Extra care here:
#   * seeded billable metrics carry DETERMINISTIC ids because the plan-create
#     request body references them by `billable_metric_id` — the replay sends
#     the manifest body verbatim, so the referenced UUID must exist in the
#     fixture on both sides;
#   * the seed runs one frozen hour BEFORE the requests so created_at values
#     differ across rows (several list endpoints sort by created_at desc and
#     not every query adds the id tie-break).

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000021"
METRIC_COUNT_ID = "1a4a0d6e-0000-4000-8000-000000000022"
METRIC_SUM_ID = "1a4a0d6e-0000-4000-8000-000000000023"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000cc"
CAPTURED_AT = Time.utc(2025, 6, 3, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {})
  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: path,
    headers: headers, body: body, required_headers: required_headers
  }

  kwargs = {headers: headers}
  kwargs[:params] = body.nil? ? nil : JSON.generate(body)
  SESSION.public_send(verb, path, **kwargs)

  File.write(
    File.join(GOLDENS, "#{MANIFEST[:requests].length}.json"),
    JSON.pretty_generate(JSON.parse(SESSION.response.body))
  )
  warn "captured ##{MANIFEST[:requests].length} #{verb.to_s.upcase} #{path} -> #{SESSION.response.status}"
end

extend ActiveSupport::Testing::TimeHelpers

organization = nil

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Plans Org",
    slug: "contract-plans-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Plans Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  # Deterministic ids: plan create references these by billable_metric_id.
  FactoryBot.create(
    :billable_metric,
    id: METRIC_COUNT_ID,
    organization: organization,
    name: "Seeded Count Metric",
    code: "seeded_count_metric",
    description: "seeded count aggregation",
    aggregation_type: "count_agg",
    recurring: false
  )

  FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_SUM_ID,
    organization: organization,
    name: "Seeded GPU Hours",
    code: "seeded_gpu_hours",
    description: "seeded sum aggregation",
    field_name: "gpu_hours",
    recurring: false
  ).tap do |metric|
    FactoryBot.create(
      :billable_metric_filter,
      billable_metric: metric,
      key: "region",
      values: ["us", "eu"]
    )
  end

  FactoryBot.create(
    :plan,
    organization: organization,
    name: "Seeded Monthly Plan",
    code: "seeded-monthly-plan",
    invoice_display_name: "Seeded Monthly",
    description: "seeded plan",
    interval: "monthly",
    amount_cents: 1000,
    amount_currency: "EUR",
    pay_in_advance: false
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

  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}
  json = auth.merge("Content-Type" => "application/json")

  # 1. create billable metric (unique_count_agg).
  capture!(:post, "/api/v1/billable_metrics",
    headers: json,
    body: {billable_metric: {
      name: "Created Unique Count",
      code: "created_unique_count",
      description: "created metric",
      aggregation_type: "unique_count_agg",
      field_name: "item_id",
      recurring: false
    }})

  # 2. index — three seeded metrics + the created one.
  capture!(:get, "/api/v1/billable_metrics", headers: auth)

  # 3. show the sum metric (carries the region filter key).
  capture!(:get, "/api/v1/billable_metrics/seeded_gpu_hours", headers: auth)

  # 4. update the same metric.
  capture!(:put, "/api/v1/billable_metrics/seeded_gpu_hours",
    headers: json,
    body: {billable_metric: {
      name: "Seeded GPU Hours Renamed",
      description: "renamed by the capture"
    }})

  # 5. create a plan with nested charges: standard, graduated, filtered.
  capture!(:post, "/api/v1/plans",
    headers: json,
    body: {plan: {
      name: "Created Plan",
      code: "created-plan",
      invoice_display_name: "Created Plan Display",
      description: "created by the capture",
      interval: "monthly",
      amount_cents: 4900,
      amount_currency: "EUR",
      pay_in_advance: false,
      charges: [
        {
          billable_metric_id: METRIC_SUM_ID,
          code: "std-charge",
          invoice_display_name: "Standard charge",
          charge_model: "standard",
          properties: {amount: "10"}
        },
        {
          billable_metric_id: METRIC_COUNT_ID,
          code: "grad-charge",
          invoice_display_name: "Graduated charge",
          charge_model: "graduated",
          properties: {graduated_ranges: [
            {from_value: 0, to_value: 10, per_unit_amount: "0", flat_amount: "200"},
            {from_value: 11, to_value: nil, per_unit_amount: "0", flat_amount: "300"}
          ]}
        },
        {
          billable_metric_id: METRIC_SUM_ID,
          code: "filtered-charge",
          invoice_display_name: "Filtered charge",
          charge_model: "standard",
          properties: {amount: "8"},
          filters: [{
            invoice_display_name: "US only",
            properties: {amount: "12"},
            values: {region: ["us"]}
          }]
        }
      ]
    }})

  # 6. plans index — seeded + created.
  capture!(:get, "/api/v1/plans", headers: auth)

  # 7. plan show (charges, filters nested).
  capture!(:get, "/api/v1/plans/created-plan", headers: auth)

  # 8. plan update.
  capture!(:put, "/api/v1/plans/created-plan",
    headers: json,
    body: {plan: {
      name: "Created Plan Renamed",
      description: "renamed by the capture",
      invoice_display_name: "Created Plan Display v2"
    }})

  # 9. charges index for the created plan.
  capture!(:get, "/api/v1/plans/created-plan/charges", headers: auth)

  # 10. single charge show.
  capture!(:get, "/api/v1/plans/created-plan/charges/grad-charge", headers: auth)

  # 11. filters index on the filtered charge.
  capture!(:get, "/api/v1/plans/created-plan/charges/filtered-charge/filters", headers: auth)

  # 12. destroy the UNATTACHED metric — success path.
  capture!(:delete, "/api/v1/billable_metrics/seeded_count_metric", headers: auth)

  # 13. destroy the ATTACHED metric — the error envelope.
  capture!(:delete, "/api/v1/billable_metrics/seeded_gpu_hours", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "plans_metrics_crud capture complete: #{MANIFEST[:requests].length} requests"
