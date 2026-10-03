# frozen_string_literal: true

# Contract scenario: invoice_standard — the synchronous invoice surface:
# a one-off invoice (POST /invoices, add-on fee with a per-fee tax) and a
# subscription invoice PREVIEW (POST /invoices/preview — full fee/tax/total
# math computed in-process, no Sidekiq), plus tax creation, tax application
# through customer create, and the invoice index.
#
# The one-off invoice's lago_id is minted by request #3, so the id-keyed
# show for it is NOT in the manifest (the replay cannot know the id); the
# scenario writes it as an EXTRA golden `10.json` and the test class
# substitutes the replay-minted id (see InvoiceStandardTest).
#
# Run via scripts/contract/capture.sh invoice_standard — see auth_org.rb for
# the general mechanics. Seed runs one frozen hour before the requests.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000041"
ADDON_ID = "1a4a0d6e-0000-4000-8000-000000000042"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000043"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000ee"
CUSTOMER_EXTERNAL_ID = "invoice-customer-1"
PREVIEW_CUSTOMER_EXTERNAL_ID = "preview-customer-1"
CAPTURED_AT = Time.utc(2025, 6, 5, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {}, golden_name: nil)
  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: path,
    headers: headers, body: body, required_headers: required_headers
  } unless golden_name

  kwargs = {headers: headers}
  kwargs[:params] = body.nil? ? nil : JSON.generate(body)
  SESSION.public_send(verb, path, **kwargs)

  name = golden_name || "#{MANIFEST[:requests].length}"
  File.write(File.join(GOLDENS, "#{name}.json"), JSON.pretty_generate(JSON.parse(SESSION.response.body)))
  warn "captured #{golden_name ? "(extra) #{golden_name}" : "##{MANIFEST[:requests].length}"} #{verb.to_s.upcase} #{path} -> #{SESSION.response.status}"
end

extend ActiveSupport::Testing::TimeHelpers

organization = nil
addon = nil
preview_customer = nil

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Invoices Org",
    slug: "contract-invoices-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Invoices Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  addon = FactoryBot.create(
    :add_on,
    id: ADDON_ID,
    organization: organization,
    name: "Seeded Setup Add-on",
    code: "setup-fee",
    invoice_display_name: "Setup fee",
    description: "seeded add-on",
    amount_cents: 1500,
    amount_currency: "EUR"
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Seeded API Calls",
    code: "api_calls",
    description: "seeded sum metric",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Invoiced Plan",
    code: "invoiced-plan",
    invoice_display_name: "Invoiced Plan Display",
    description: "plan for the preview",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :standard_charge,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "api-calls-charge",
    invoice_display_name: "API calls",
    properties: {amount: "0.10"}
  )

  # The preview customer carries the organization tax directly (seeded —
  # request-applied taxes are exercised on the invoice customer below).
  vat = FactoryBot.create(
    :tax,
    organization: organization,
    code: "preview-vat-20",
    name: "Preview VAT",
    rate: 20.0
  )

  preview_customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: PREVIEW_CUSTOMER_EXTERNAL_ID,
    name: "Preview Customer Co.",
    email: "preview@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "5 Rue de la Facture",
    zipcode: "75003",
    state: "IDF"
  )

  FactoryBot.create(:customer_applied_tax, customer: preview_customer, tax: vat, organization: organization)
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

  # 1. create the VAT tax for the invoice customer.
  capture!(:post, "/api/v1/taxes",
    headers: json,
    body: {tax: {
      name: "Invoice VAT",
      code: "invoice-vat-20",
      rate: "20.0",
      description: "applied to the invoice customer"
    }})

  # 2. create the invoice customer WITH the tax applied (tax_codes).
  capture!(:post, "/api/v1/customers",
    headers: json,
    body: {customer: {
      external_id: CUSTOMER_EXTERNAL_ID,
      name: "Invoice Customer Co.",
      email: "invoice-customer@example.invalid",
      currency: "EUR",
      country: "FR",
      city: "Paris",
      address_line1: "9 Rue de la Facture",
      zipcode: "75004",
      state: "IDF",
      tax_codes: ["invoice-vat-20"]
    }})

  # 3. one-off invoice: add-on fee, 2 units at 1200 cents, per-fee VAT 20%.
  #    (Invoices::CreateOneOffService — fully synchronous.)
  capture!(:post, "/api/v1/invoices",
    headers: json,
    body: {invoice: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      currency: "EUR",
      fees: [{
        add_on_code: "setup-fee",
        invoice_display_name: "One-off setup",
        unit_amount_cents: 1200,
        units: "2",
        description: "capture one-off fee",
        tax_codes: ["invoice-vat-20"]
      }]
    }})

  # 4. invoice index scoped to the invoice customer.
  capture!(:get, "/api/v1/invoices?external_customer_id=#{CUSTOMER_EXTERNAL_ID}", headers: auth)

  # 5. subscription invoice preview — synchronous fee/tax/total math for a
  #    calendar-monthly billing of the charged plan against the taxed
  #    preview customer.
  capture!(:post, "/api/v1/invoices/preview",
    headers: json,
    body: {
      external_customer_id: PREVIEW_CUSTOMER_EXTERNAL_ID,
      plan_code: "invoiced-plan",
      billing_time: "calendar"
    })

  # EXTRA golden 10.json (not in the manifest): show the invoice created by
  # request #3, addressed by the id IT minted. The replay substitutes the
  # id its own request #3 minted — see InvoiceStandardTest.
  invoice_id = JSON.parse(File.read(File.join(GOLDENS, "3.json"))).dig("invoice", "lago_id")
  capture!(:get, "/api/v1/invoices/#{invoice_id}", headers: auth, golden_name: "10")
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "invoice_standard capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
