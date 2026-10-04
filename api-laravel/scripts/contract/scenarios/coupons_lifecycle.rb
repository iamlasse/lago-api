# frozen_string_literal: true

# Contract scenario: coupons_lifecycle — coupon create (fixed + percentage),
# index/show/update, applying both to a customer, the applied-coupons index,
# update-AFTER-apply (name/description/expiration still mutable — everything
# pricing-related is silently IGNORED while the coupon has applied
# instances), terminating an applied coupon through the customer-nested
# destroy, and coupon destroy (discards the coupon and terminates its active
# applied coupons).
#
# The applied-coupon ids are minted by their create requests, so the
# customer-nested DELETE carries a TOKEN in the manifest (see wallets_lifecycle.rb
# for the token mechanics).
#
# Run via scripts/contract/capture.sh coupons_lifecycle — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000111"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000001cc"
CUSTOMER_EXTERNAL_ID = "coupon-customer-1"
CAPTURED_AT = Time.utc(2025, 6, 7, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
EXPIRATION_AT = Time.utc(2025, 9, 7, 0, 0, 0)

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
    name: "Contract Coupons Org",
    slug: "contract-coupons-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Coupons Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Coupon Customer Co.",
    firstname: "Cou",
    lastname: "Pon",
    email: "coupon-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "4 Rue des Reductions",
    zipcode: "75007",
    state: "IDF"
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

  # 1. fixed-amount coupon, once frequency.
  capture!(:post, "/api/v1/coupons",
    headers: json,
    body: {coupon: {
      name: "Fixed Coupon",
      code: "fixed-10",
      description: "ten euros off",
      coupon_type: "fixed_amount",
      amount_cents: 1000,
      amount_currency: "EUR",
      frequency: "once",
      expiration: "time_limit",
      expiration_at: EXPIRATION_AT.iso8601,
      reusable: false
    }})

  # 2. percentage coupon, recurring for 3 invoices (one frozen second later —
  #    the index sorts created_at desc and both coupons would otherwise tie).
  capture!(:post, "/api/v1/coupons",
    headers: json, at: CAPTURED_AT + 1,
    body: {coupon: {
      name: "Percentage Coupon",
      code: "pct-25",
      description: "25 percent off",
      coupon_type: "percentage",
      percentage_rate: "25.0",
      frequency: "recurring",
      frequency_duration: 3,
      expiration: "no_expiration"
    }})

  # 3. coupon index — two rows, newest (percentage) first.
  capture!(:get, "/api/v1/coupons", headers: auth)

  # 4. show by code.
  capture!(:get, "/api/v1/coupons/fixed-10", headers: auth)

  # 5. update BEFORE any application — every field is mutable.
  capture!(:put, "/api/v1/coupons/fixed-10",
    headers: json,
    body: {coupon: {
      name: "Fixed Coupon Renamed",
      description: "still ten euros off",
      amount_cents: 1200,
      expiration_at: EXPIRATION_AT.iso8601
    }})

  # 6. apply the fixed coupon to the customer.
  capture!(:post, "/api/v1/applied_coupons",
    headers: json,
    body: {applied_coupon: {external_customer_id: CUSTOMER_EXTERNAL_ID, coupon_code: "fixed-10"}})

  # 7. apply the percentage coupon (one frozen second later).
  capture!(:post, "/api/v1/applied_coupons",
    headers: json, at: CAPTURED_AT + 2,
    body: {applied_coupon: {external_customer_id: CUSTOMER_EXTERNAL_ID, coupon_code: "pct-25"}})

  # 8. applied-coupons index — two active rows, newest first.
  capture!(:get, "/api/v1/applied_coupons", headers: auth)

  # 9. update AFTER apply — the immutability contract: name, description and
  #    expiration still change; amount_cents / code / frequency are silently
  #    IGNORED while the coupon has applied instances (see
  #    Coupons::UpdateService#call — the pricing fields are guarded by
  #    `unless coupon_already_applied`).
  capture!(:put, "/api/v1/coupons/fixed-10",
    headers: json,
    body: {coupon: {
      name: "Fixed Coupon After Apply",
      amount_cents: 9999,
      code: "renamed-after-apply",
      frequency: "recurring",
      frequency_duration: 7
    }})

  # 10. terminate the FIXED applied coupon via the customer-nested destroy.
  #     The applied coupon's id is minted by request #6 — tokenized here and
  #     substituted by the replay (see CouponsLifecycleTest).
  fixed_applied_id = JSON.parse(File.read(File.join(GOLDENS, "6.json"))).dig("applied_coupon", "lago_id")
  capture!(:delete, "/api/v1/customers/#{CUSTOMER_EXTERNAL_ID}/applied_coupons/#{fixed_applied_id}",
    headers: auth, at: CAPTURED_AT + 3,
    tokens: {fixed_applied_id => "FIXED_APPLIED_COUPON_ID"})

  # 11. applied-coupons index again — the fixed row now status terminated.
  capture!(:get, "/api/v1/applied_coupons", headers: auth, at: CAPTURED_AT + 4)

  # 12. destroy the fixed coupon — unattached now, plain discard.
  capture!(:delete, "/api/v1/coupons/fixed-10", headers: auth)

  # 13. destroy the percentage coupon WHILE STILL APPLIED — destroys anyway
  #     and terminates its active applied coupons (Coupons::DestroyService).
  capture!(:delete, "/api/v1/coupons/pct-25", headers: auth)

  # 14. applied-coupons index — both rows terminated.
  capture!(:get, "/api/v1/applied_coupons", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "coupons_lifecycle capture complete: #{MANIFEST[:requests].length} requests"
