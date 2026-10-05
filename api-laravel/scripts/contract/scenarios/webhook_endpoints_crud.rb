# frozen_string_literal: true

# Contract scenario: webhook_endpoints_crud — /api/v1/webhook_endpoints:
# create (hmac + a filtered event_types list), create with ["*"] (normalized
# to nil = filtering DISABLED — the endpoint receives everything), index
# (webhook_url asc / created_at desc), show by id, update (rename + algo +
# event_types replacement), the invalid-types 422, the non-array event_types
# 422, destroy, and the final index.
#
# Endpoint ids are minted by the create requests, so the id-keyed paths carry
# TOKENS in the manifest (see wallets_lifecycle.rb for the mechanics) — the
# replay test substitutes the ids its own requests minted.
#
# Run via scripts/contract/capture.sh webhook_endpoints_crud — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000071"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000ac"
CAPTURED_AT = Time.utc(2025, 6, 12, 13, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

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
    name: "Contract Webhooks Org",
    slug: "contract-webhooks-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Webhooks Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)
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

# 1. hmac endpoint with a filtered event_types list (normalized: stripped,
#    downcased, deduped — kept as the array).
capture!(:post, "/api/v1/webhook_endpoints",
  headers: json,
  body: {webhook_endpoint: {
    webhook_url: "https://hooks.example.invalid/lago-a",
    name: "Primary Hook",
    signature_algo: "hmac",
    event_types: ["invoice.created", "customer.created"]
  }})

# 2. ["*"] is the special case: normalized to nil — filtering DISABLED.
#    Golden event_types is null while the endpoint receives EVERYTHING.
capture!(:post, "/api/v1/webhook_endpoints", at: CAPTURED_AT + 1,
  headers: json,
  body: {webhook_endpoint: {
    webhook_url: "https://hooks.example.invalid/lago-b",
    name: "All Events Hook",
    event_types: ["*"]
  }})

# 3. index — WebhookEndpointsQuery orders webhook_url asc, created_at desc.
capture!(:get, "/api/v1/webhook_endpoints", headers: auth)

endpoint_one_id = JSON.parse(File.read(File.join(GOLDENS, "1.json"))).dig("webhook_endpoint", "lago_id")
endpoint_two_id = JSON.parse(File.read(File.join(GOLDENS, "2.json"))).dig("webhook_endpoint", "lago_id")

# 4. show by id (id minted by request #1 — tokenized).
capture!(:get, "/api/v1/webhook_endpoints/#{endpoint_one_id}",
  headers: auth, tokens: {endpoint_one_id => "WEBHOOK_ENDPOINT_ONE_ID"})

# 5. update — rename, algo back to jwt, event_types REPLACED with a single
#    entry (UpdateService assigns the whole array).
capture!(:put, "/api/v1/webhook_endpoints/#{endpoint_one_id}",
  headers: json,
  body: {webhook_endpoint: {
    name: "Primary Hook Renamed",
    signature_algo: "jwt",
    event_types: ["invoice.created"]
  }},
  tokens: {endpoint_one_id => "WEBHOOK_ENDPOINT_ONE_ID"})

# 6. invalid event type — model validation envelope listing the offenders.
capture!(:post, "/api/v1/webhook_endpoints",
  headers: json,
  body: {webhook_endpoint: {
    webhook_url: "https://hooks.example.invalid/lago-c",
    event_types: ["invoice.created", "not_a_real_event"]
  }})

# 7. NON-ARRAY event_types — the controller preserves the raw scalar
#    (params.permit with event_types: [] drops it) so the model can raise
#    must_be_array.
capture!(:post, "/api/v1/webhook_endpoints",
  headers: json,
  body: {webhook_endpoint: {
    webhook_url: "https://hooks.example.invalid/lago-d",
    event_types: "invoice.created"
  }})

# 8. destroy the wildcard endpoint.
capture!(:delete, "/api/v1/webhook_endpoints/#{endpoint_two_id}",
  headers: auth, at: CAPTURED_AT + 2,
  tokens: {endpoint_two_id => "WEBHOOK_ENDPOINT_TWO_ID"})

# 9. index again — one endpoint left.
capture!(:get, "/api/v1/webhook_endpoints", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "webhook_endpoints_crud capture complete: #{MANIFEST[:requests].length} requests"
