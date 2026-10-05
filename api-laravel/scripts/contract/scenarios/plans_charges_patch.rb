# frozen_string_literal: true

# Contract scenario: plans_charges_patch — nested PATCH
# /api/v1/plans/:plan_code/charges/:code. Rails maps PATCH to the same
# Plans::ChargesController#update the PUT route hits (verified:
# plan_nested_api.rb `resources :charges` and no verb branch in the
# controller chain). The plan + charge are seeded; the PATCH mirrors the
# charge-update body the feature suite ports from the PUT spec.
#
# Run via scripts/contract/capture.sh plans_charges_patch — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000181"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000182"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000002c1"
CAPTURED_AT = Time.utc(2025, 6, 14, 12, 0, 0)
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

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Charges Patch Org",
    slug: "contract-charges-patch-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Charges Patch Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  metric = FactoryBot.create(
    :billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Patch Charge Metric",
    code: "patch_charge_metric",
    aggregation_type: "count_agg",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Charges Patch Plan",
    code: "charges-patch-plan",
    interval: "monthly",
    amount_cents: 1500,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :standard_charge,
    billable_metric: metric,
    plan: plan,
    organization: organization,
    code: "patched-charge",
    invoice_display_name: "Patched Charge",
    properties: {amount: "10"}
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

  # 1. nested PATCH of the charge — the same update action the PUT route hits.
  capture!(:patch, "/api/v1/plans/charges-patch-plan/charges/patched-charge",
    headers: json,
    body: {charge: {
      invoice_display_name: "Patched Charge v2",
      charge_model: "standard",
      properties: {amount: "20"}
    }})
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "plans_charges_patch capture complete: #{MANIFEST[:requests].length} requests"
