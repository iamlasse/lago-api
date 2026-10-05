# frozen_string_literal: true

# Contract scenario: coupons_patch — PATCH /api/v1/coupons/:code. Rails maps
# PATCH to the same CouponsController#update the PUT route hits (verified:
# config/routes.rb `resources :coupons` and no request.patch?/verb branch in
# the controller chain), so the request body mirrors coupons_lifecycle's
# pre-apply PUT. The coupon is seeded (never applied), so every field is
# mutable.
#
# Run via scripts/contract/capture.sh coupons_patch — see auth_org.rb for the
# general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000161"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000002a1"
CAPTURED_AT = Time.utc(2025, 6, 12, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
EXPIRATION_AT = Time.utc(2025, 9, 12, 0, 0, 0)

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
    name: "Contract Coupons Patch Org",
    slug: "contract-coupons-patch-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Coupons Patch Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :coupon,
    organization: organization,
    name: "Patch Coupon",
    code: "patch-coupon",
    coupon_type: "fixed_amount",
    amount_cents: 1000,
    amount_currency: "EUR",
    frequency: "once",
    expiration: "time_limit",
    expiration_at: EXPIRATION_AT
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

  # 1. PATCH the coupon before any application — every field is mutable, the
  #    same update action the PUT route hits.
  capture!(:patch, "/api/v1/coupons/patch-coupon",
    headers: json,
    body: {coupon: {
      name: "Patch Coupon Renamed",
      description: "renamed via PATCH",
      amount_cents: 1200,
      expiration_at: EXPIRATION_AT.iso8601
    }})
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "coupons_patch capture complete: #{MANIFEST[:requests].length} requests"
