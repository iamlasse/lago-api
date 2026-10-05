# frozen_string_literal: true

# Contract scenario: taxes_crud — the /api/v1/taxes surface: create (plain +
# applied_to_organization, which attaches the tax to the default billing
# entity), index (name-ordered TaxWithBillingEntitiesSerializer), show by
# code, update (rate change + applied_to_organization toggle, which runs
# ApplyTaxes/RemoveTaxes against the billing entity), the duplicate-code 422,
# the 404 on an unknown code, and destroy (detach + discard).
#
# Run via scripts/contract/capture.sh taxes_crud — see auth_org.rb for the
# general mechanics. Seed runs one frozen hour before the requests.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000061"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000ab"
CAPTURED_AT = Time.utc(2025, 6, 12, 12, 0, 0)
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
    name: "Contract Taxes Org",
    slug: "contract-taxes-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Taxes Capture Key")
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

# 1. create a plain customer-attach tax.
capture!(:post, "/api/v1/taxes",
  headers: json,
  body: {tax: {
    name: "VAT Twenty",
    code: "vat-20",
    rate: "20.0",
    description: "captured VAT"
  }})

# 2. create an organization-wide tax — CreateService also calls
#    BillingEntities::Taxes::ApplyTaxesService for the default billing
#    entity, so the serializer reports applied_to_organization: true.
capture!(:post, "/api/v1/taxes", at: CAPTURED_AT + 1,
  headers: json,
  body: {tax: {
    name: "Regional Levy",
    code: "regional-levy",
    rate: "5.5",
    description: "org-wide levy",
    applied_to_organization: true
  }})

# 3. index — TaxesQuery orders by name by default: Regional Levy first.
capture!(:get, "/api/v1/taxes", headers: auth)

# 4. show by code.
capture!(:get, "/api/v1/taxes/vat-20", headers: auth)

# 5. update — rate + description.
capture!(:put, "/api/v1/taxes/vat-20",
  headers: json,
  body: {tax: {rate: "22.5", description: "raised by the capture"}})

# 6. update — applied_to_organization flip to false runs RemoveTaxes on
#    the billing entity and flags the org's draft invoices for refresh.
capture!(:put, "/api/v1/taxes/regional-levy",
  headers: json,
  body: {tax: {applied_to_organization: false}})

# 7. duplicate code — the uniqueness validation envelope.
capture!(:post, "/api/v1/taxes",
  headers: json,
  body: {tax: {name: "Duplicate VAT", code: "vat-20", rate: "1.0"}})

# 8. unknown code — not_found_error.
capture!(:get, "/api/v1/taxes/does-not-exist", headers: auth)

# 9. destroy — detaches from billing entities, wipes applied_taxes, discards.
capture!(:delete, "/api/v1/taxes/regional-levy", headers: auth)

# 10. index again — the discarded tax is gone (default_scope kept).
capture!(:get, "/api/v1/taxes", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "taxes_crud capture complete: #{MANIFEST[:requests].length} requests"
