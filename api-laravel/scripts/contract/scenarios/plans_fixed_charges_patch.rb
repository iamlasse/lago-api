# frozen_string_literal: true

# Contract scenario: plans_fixed_charges_patch — nested PATCH
# /api/v1/plans/:plan_code/fixed_charges/:code. Rails maps PATCH to the same
# Plans::FixedChargesController#update the PUT route hits (verified:
# plan_nested_api.rb `resources :fixed_charges` and no verb branch in the
# controller chain). The plan + fixed charge are seeded; the PATCH mirrors
# the fixed-charge-update body the feature suite ports from the PUT spec.
#
# Run via scripts/contract/capture.sh plans_fixed_charges_patch — see
# auth_org.rb for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000191"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000002d1"
CAPTURED_AT = Time.utc(2025, 6, 15, 12, 0, 0)
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
    name: "Contract Fixed Charges Patch Org",
    slug: "contract-fixed-charges-patch-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Fixed Charges Patch Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Fixed Charges Patch Plan",
    code: "fixed-charges-patch-plan",
    interval: "monthly",
    amount_cents: 1500,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  add_on = FactoryBot.create(:add_on, organization: organization)

  FactoryBot.create(
    :fixed_charge,
    plan: plan,
    organization: organization,
    add_on: add_on,
    code: "patched-fixed-charge",
    invoice_display_name: "Patched Fixed Charge",
    charge_model: "standard",
    units: 1,
    properties: {amount: "100"}
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

  # 1. nested PATCH of the fixed charge — the same update action the PUT
  #    route hits.
  capture!(:patch, "/api/v1/plans/fixed-charges-patch-plan/fixed_charges/patched-fixed-charge",
    headers: json,
    body: {fixed_charge: {
      invoice_display_name: "Patched Fixed Charge v2",
      charge_model: "standard",
      units: 20,
      properties: {amount: "200"}
    }})
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "plans_fixed_charges_patch capture complete: #{MANIFEST[:requests].length} requests"
