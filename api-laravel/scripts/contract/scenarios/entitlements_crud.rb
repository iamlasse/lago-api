# frozen_string_literal: true

# Contract scenario: entitlements_crud — the entitlements surface:
# feature create with typed privileges (integer / boolean), feature
# index/show/update, plan entitlements PATCH (partial upsert) and POST
# (full replacement), plan entitlements index/show, subscription
# entitlements PATCH (override), subscription entitlements index (merged
# plan values + overrides), and both destroy paths.
#
# Values semantics: a privilege value is stored per value_type — the
# boolean privilege round-trips as true/false (NOT "t"/"f" strings), the
# integer one as a number, even though the API input is the string "10".
#
# Run via scripts/contract/capture.sh entitlements_crud — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000141"
PLAN_ID = "1a4a0d6e-0000-4000-8000-000000000142"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000001ff"
CUSTOMER_EXTERNAL_ID = "entitlement-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "entitlement-sub-1"
PLAN_CODE = "entitlement-plan"
CAPTURED_AT = Time.utc(2025, 6, 10, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
SUBSCRIPTION_AT = Time.utc(2025, 5, 15, 0, 0, 0)

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
    name: "Contract Entitlements Org",
    slug: "contract-entitlements-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Entitlements Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Entitlement Customer Co.",
    firstname: "Ent",
    lastname: "Itled",
    email: "entitlement-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "8 Rue des Fonctions",
    zipcode: "75010",
    state: "IDF"
  )

  plan = FactoryBot.create(
    :plan,
    id: PLAN_ID,
    organization: organization,
    name: "Entitlement Plan",
    code: PLAN_CODE,
    invoice_display_name: "Entitlement Plan Display",
    description: "plan carrying entitlements",
    interval: "monthly",
    amount_cents: 2900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan,
    external_id: SUBSCRIPTION_EXTERNAL_ID,
    name: "Entitlement Sub",
    status: "active",
    billing_time: "calendar",
    subscription_at: SUBSCRIPTION_AT,
    started_at: SUBSCRIPTION_AT,
    activated_at: SUBSCRIPTION_AT
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

  # 1. feature create with two typed privileges.
  capture!(:post, "/api/v1/features",
    headers: json,
    body: {feature: {
      code: "seats",
      name: "Seat Management",
      description: "control the seat count",
      privileges: [
        {code: "max_seats", name: "Maximum seats", value_type: "integer"},
        {code: "premium_support", name: "Premium support", value_type: "boolean"}
      ]
    }})

  # 2. features index — one feature.
  capture!(:get, "/api/v1/features", headers: auth)

  # 3. feature show.
  capture!(:get, "/api/v1/features/seats", headers: auth)

  # 4. feature update — rename + EXTEND the privilege set. NOTE: a feature
  #    update REPLACES the whole privilege set (Features::UpdateService), so
  #    the two existing privileges are re-declared alongside the new one —
  #    dropping them here would 404 every later privilege reference.
  capture!(:put, "/api/v1/features/seats",
    headers: json,
    body: {feature: {
      name: "Seat Management Renamed",
      privileges: [
        {code: "max_seats", name: "Maximum seats", value_type: "integer"},
        {code: "premium_support", name: "Premium support", value_type: "boolean"},
        {code: "priority_level", name: "Priority level", value_type: "string"}
      ]
    }})

  # 5. plan entitlements PATCH — partial upsert of the two ORIGINAL
  #    privileges (max_seats as the STRING "10" — typed to integer 10 in the
  #    golden — and premium_support as the boolean true).
  capture!(:patch, "/api/v1/plans/#{PLAN_CODE}/entitlements",
    headers: json,
    body: {entitlements: {
      seats: {"max_seats" => "10", "premium_support" => true}
    }})

  # 6. plan entitlements POST — FULL replacement semantics (partial: false):
  #    the privilege set collapses to max_seats + priority_level;
  #    premium_support is dropped.
  capture!(:post, "/api/v1/plans/#{PLAN_CODE}/entitlements",
    headers: json,
    body: {entitlements: {
      seats: {"max_seats" => "20", "priority_level" => "high"}
    }})

  # 7. plan entitlements index — one entry, two privilege values.
  capture!(:get, "/api/v1/plans/#{PLAN_CODE}/entitlements", headers: auth)

  # 8. plan entitlement show.
  capture!(:get, "/api/v1/plans/#{PLAN_CODE}/entitlements/seats", headers: auth)

  # 9. subscription entitlements PATCH — override max_seats only.
  capture!(:patch, "/api/v1/subscriptions/#{SUBSCRIPTION_EXTERNAL_ID}/entitlements",
    headers: json,
    body: {entitlements: {
      seats: {"max_seats" => "50"}
    }})

  # 10. subscription entitlements index — the merged view: the override
  #     (50) plus the plan-inherited values (priority_level high).
  capture!(:get, "/api/v1/subscriptions/#{SUBSCRIPTION_EXTERNAL_ID}/entitlements", headers: auth)

  # 11. subscription override destroy — back to the plan values.
  capture!(:delete, "/api/v1/subscriptions/#{SUBSCRIPTION_EXTERNAL_ID}/entitlements/seats", headers: auth)

  # 12. plan entitlement destroy — the feature drops off the plan.
  capture!(:delete, "/api/v1/plans/#{PLAN_CODE}/entitlements/seats", headers: auth)

  # 13. plan entitlements index after destroy — empty.
  capture!(:get, "/api/v1/plans/#{PLAN_CODE}/entitlements", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "entitlements_crud capture complete: #{MANIFEST[:requests].length} requests"
