# frozen_string_literal: true

# Contract scenario: subscription_create — subscriptions on a pay-in-advance
# plan and on an arrears plan, both started "today" under the frozen clock:
# create, show, index, update (rename), terminate (DELETE — soft), index
# again, and show-after-terminate.
#
# Run via scripts/contract/capture.sh subscription_create — see auth_org.rb
# for the general mechanics. The seed runs one frozen hour BEFORE the
# requests so list ordering (created_at desc) is stable.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000031"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000dd"
CUSTOMER_EXTERNAL_ID = "seed-customer-1"
CAPTURED_AT = Time.utc(2025, 6, 4, 12, 0, 0)
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
    name: "Contract Subs Org",
    slug: "contract-subs-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Subs Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Subscription Customer Co.",
    firstname: "Sub",
    lastname: "Scriber",
    email: "subscriber@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "1 Rue de l'Abonnement",
    zipcode: "75002",
    state: "IDF"
  )

  FactoryBot.create(
    :plan,
    organization: organization,
    name: "Advance Plan",
    code: "plan-advance",
    invoice_display_name: "Advance Plan Display",
    description: "pay in advance plan",
    interval: "monthly",
    amount_cents: 1000,
    amount_currency: "EUR",
    pay_in_advance: true
  )

  FactoryBot.create(
    :plan,
    organization: organization,
    name: "Arrears Plan",
    code: "plan-arrears",
    invoice_display_name: "Arrears Plan Display",
    description: "pay in arrears plan",
    interval: "monthly",
    amount_cents: 2000,
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

  # 1. create on the pay-in-advance plan (started today → active).
  capture!(:post, "/api/v1/subscriptions",
    headers: json,
    body: {subscription: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      plan_code: "plan-advance",
      external_id: "sub-advance-1",
      name: "Advance Sub",
      billing_time: "calendar"
    }})

  # 2. create on the arrears plan (default billing_time), explicitly started
  #    one frozen hour earlier: the index sorts by subscription_at DESC and
  #    both creates would otherwise tie on created_at, leaving the order to
  #    the minted-id tie-break (unstable across runtimes).
  capture!(:post, "/api/v1/subscriptions",
    headers: json,
    body: {subscription: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      plan_code: "plan-arrears",
      external_id: "sub-arrears-1",
      name: "Arrears Sub",
      subscription_at: SEEDED_AT.iso8601
    }})

  # 3. show the advance subscription.
  capture!(:get, "/api/v1/subscriptions/sub-advance-1", headers: auth)

  # 4. index — two active subscriptions.
  capture!(:get, "/api/v1/subscriptions", headers: auth)

  # 5. update (rename) the arrears subscription.
  capture!(:put, "/api/v1/subscriptions/sub-arrears-1",
    headers: json,
    body: {subscription: {name: "Arrears Sub Renamed"}})

  # 6. terminate the advance subscription (DELETE soft-terminates).
  capture!(:delete, "/api/v1/subscriptions/sub-advance-1", headers: auth)

  # 7. index — terminated subscription still listed.
  capture!(:get, "/api/v1/subscriptions", headers: auth)

  # 8. show after terminate — status terminated.
  capture!(:get, "/api/v1/subscriptions/sub-advance-1", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "subscription_create capture complete: #{MANIFEST[:requests].length} requests"
