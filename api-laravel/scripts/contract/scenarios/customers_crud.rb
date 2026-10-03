# frozen_string_literal: true

# Contract scenario: customers_crud — the REST customers surface:
# create (upsert), show, update-by-recreate, index, destroy + the two
# not-found envelopes (unknown external_id on show, show after destroy).
#
# Run via scripts/contract/capture.sh customers_crud — see auth_org.rb for
# the general mechanics (deterministic ids, frozen clock, seed-state dump
# BEFORE the requests, in-process Integration::Session requests).

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000011"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000bb"
SEEDED_EXTERNAL_ID = "seed-customer-1"
CAPTURED_EXTERNAL_ID = "captured-customer-1"
CAPTURED_AT = Time.utc(2025, 6, 2, 12, 0, 0)
# Seeds run one frozen hour BEFORE the requests: the customers index sorts
# by created_at with a minted-id tie-break, and the seeded + request-created
# customers must not tie (that ordering is unstable across runtimes).
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
    name: "Contract Customers Org",
    slug: "contract-customers-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Customers Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  # A pre-seeded customer so the index responses have stable content before
  # any request runs. Every attribute is explicit — Faker values would still
  # be fine (the fixture restores them) but determinism is free here.
  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: SEEDED_EXTERNAL_ID,
    name: "Seeded Customer Co.",
    firstname: "Seed",
    lastname: "Ed",
    customer_type: "individual",
    country: "FR",
    address_line1: "10 Rue du Seed",
    address_line2: "Apt 1",
    state: "IDF",
    zipcode: "75001",
    email: "seeded@example.invalid",
    city: "Paris",
    url: "https://seeded.example.invalid",
    phone: "+33-1-00-00-00-00",
    legal_name: nil,
    legal_number: nil,
    currency: "EUR"
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

  # 1. index — one seeded customer.
  capture!(:get, "/api/v1/customers", headers: auth)

  # 2. create (upsert path, insert branch) — full payload.
  capture!(:post, "/api/v1/customers",
    headers: json,
    body: {customer: {
      external_id: CAPTURED_EXTERNAL_ID,
      name: "Captured Customer Inc.",
      firstname: "Cap",
      lastname: "Tured",
      customer_type: "company",
      country: "US",
      address_line1: "1 Capture Way",
      address_line2: "Suite 2",
      state: "CA",
      zipcode: "94105",
      email: "captured@example.invalid",
      city: "San Francisco",
      url: "https://example.invalid",
      phone: "+1-555-0100",
      legal_name: "Captured Customer Legal",
      legal_number: "123456789",
      tax_identification_number: "US-123456789",
      currency: "EUR",
      timezone: "America/Los_Angeles",
      net_payment_term: 10
    }})

  # 3. show.
  capture!(:get, "/api/v1/customers/#{CAPTURED_EXTERNAL_ID}", headers: auth)

  # 4. create again with the SAME external_id — the upsert update branch
  #    (rename + metadata).
  capture!(:post, "/api/v1/customers",
    headers: json,
    body: {customer: {
      external_id: CAPTURED_EXTERNAL_ID,
      name: "Captured Customer Renamed",
      email: "renamed@example.invalid",
      metadata: [{key: "channel", value: "contract", display_in_invoice: false}]
    }})

  # 5. index — two customers now.
  capture!(:get, "/api/v1/customers", headers: auth)

  # 6. unknown external_id — the 404 envelope.
  capture!(:get, "/api/v1/customers/does-not-exist", headers: auth)

  # 7. destroy.
  capture!(:delete, "/api/v1/customers/#{CAPTURED_EXTERNAL_ID}", headers: auth)

  # 8. show after destroy — 404 again.
  capture!(:get, "/api/v1/customers/#{CAPTURED_EXTERNAL_ID}", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "customers_crud capture complete: #{MANIFEST[:requests].length} requests"
