# frozen_string_literal: true

# Contract scenario: invoice_actions — the invoice lifecycle member actions:
# a one-off invoice (minted id — show + PATCH payment_status on it), then on
# a SEEDED DRAFT subscription invoice: PUT refresh (fee recomputation),
# the metadata-on-draft 405, PUT finalize, show, payment_url without a
# provider (422 no_linked_payment_provider), resend_email (403
# premium_license_required — premium-gated in the OSS capture stack), the
# dispute-lost flip, POST void, the update-on-voided 405, retry on a
# non-failed invoice (invalid_status), and the org-wide index.
#
# The one-off invoice's lago_id is minted by request #1, so the id-keyed
# paths carry TOKENS in the manifest (see wallets_lifecycle.rb for the
# mechanics) — the replay test substitutes the id its own request minted.
# The draft invoice is SEEDED with a deterministic id, so its paths are
# verbatim.
#
# Run via scripts/contract/capture.sh invoice_actions — see auth_org.rb for
# the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000081"
ADDON_ID = "1a4a0d6e-0000-4000-8000-000000000082"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000083"
DRAFT_INVOICE_ID = "1a4a0d6e-0000-4000-8000-000000000084"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000ad"
CUSTOMER_EXTERNAL_ID = "invoice-actions-customer"
CAPTURED_AT = Time.utc(2025, 6, 12, 14, 0, 0)
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

  raw = SESSION.response.body
  parsed = raw.strip.empty? ? nil : JSON.parse(raw)
  File.write(
    File.join(GOLDENS, "#{MANIFEST[:requests].length}.json"),
    JSON.pretty_generate(parsed)
  )
  warn "captured ##{MANIFEST[:requests].length} #{verb.to_s.upcase} #{path} -> #{SESSION.response.status}"
end

extend ActiveSupport::Testing::TimeHelpers

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Invoice Actions Org",
    slug: "contract-invoice-actions-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Invoice Actions Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :add_on,
    id: ADDON_ID,
    organization: organization,
    name: "Actions Setup Add-on",
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
    name: "Actions API Calls",
    code: "api_calls",
    description: "seeded sum metric",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Actions Plan",
    code: "actions-plan",
    invoice_display_name: "Actions Plan Display",
    description: "plan for the draft invoice",
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

  vat = FactoryBot.create(
    :tax,
    organization: organization,
    code: "actions-vat-20",
    name: "Actions VAT",
    rate: 20.0
  )

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Actions Customer Co.",
    email: "actions-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "11 Rue des Actions",
    zipcode: "75002",
    state: "IDF"
  )

  FactoryBot.create(:customer_applied_tax, customer: customer, tax: vat, organization: organization)

  # Started exactly at the calendar month boundary — the refreshed/finalized
  # draft bills the FULL June period (no proration, deterministic math).
  subscription = FactoryBot.create(
    :subscription,
    :calendar,
    organization: organization,
    customer: customer,
    plan: plan,
    external_id: "invoice-actions-sub-1",
    started_at: Time.utc(2025, 6, 1, 0, 0, 0),
    activated_at: Time.utc(2025, 6, 1, 0, 0, 0),
    subscription_at: Time.utc(2025, 6, 1, 0, 0, 0)
  )

  FactoryBot.create(
    :invoice,
    :draft,
    :subscription,
    id: DRAFT_INVOICE_ID,
    organization: organization,
    customer: customer,
    subscriptions: [subscription]
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

# 1. one-off invoice — add-on fee, 2 units at 1200 cents, per-fee VAT 20%
#    (the customer ALSO carries the seeded 20% tax — per-fee tax_codes wins
#    for the fee; both are deterministic).
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
      tax_codes: ["actions-vat-20"]
    }]
  }})

invoice_id = JSON.parse(File.read(File.join(GOLDENS, "1.json"))).dig("invoice", "lago_id")
invoice_tokens = {invoice_id => "ONE_OFF_INVOICE_ID"}
draft_path = "/api/v1/invoices/#{DRAFT_INVOICE_ID}"

# 2. show the minted one-off invoice (tokenized id).
capture!(:get, "/api/v1/invoices/#{invoice_id}", headers: auth, tokens: invoice_tokens)

# 3. PATCH payment_status on the one-off invoice — UpdateService path
#    (also flips the fee payment statuses).
capture!(:patch, "/api/v1/invoices/#{invoice_id}",
  headers: json,
  body: {invoice: {payment_status: "succeeded"}},
  tokens: invoice_tokens)

# 4. PUT refresh on the DRAFT subscription invoice — RefreshDraftService
#    wipes and recomputes fees from the subscription (plan 4900 full June
#    period + 20% customer tax, zero-event charge fee).
capture!(:put, "#{draft_path}/refresh", headers: auth)

# 5. metadata on a DRAFT invoice — the 405 envelope.
capture!(:patch, draft_path,
  headers: json,
  body: {invoice: {metadata: [{key: "po-number", value: "PO-77"}]}})

# 6. finalize — RefreshDraftAndFinalizeService (refresh inside, then the
#    transition to the final status).
capture!(:put, "#{draft_path}/finalize", headers: auth)

# 7. show the finalized invoice (deterministic id — verbatim path).
capture!(:get, draft_path, headers: auth)

# 8. payment_url with NO payment provider on the customer — 422 envelope.
capture!(:post, "#{draft_path}/payment_url", headers: auth)

# 9. resend_email — PREMIUM-GATED (Emails::ResendService): the OSS capture
#    stack answers 403 premium_license_required. That envelope IS the
#    contract for this capture stack (same precedent as invoice preview).
capture!(:post, "#{draft_path}/resend_email",
  headers: json,
  body: {to: ["billing@example.invalid"]})

# 10. lose_dispute on the finalized invoice — stamps
#     payment_dispute_lost_at at the frozen instant.
capture!(:post, "#{draft_path}/lose_dispute", headers: auth)

# 11. void the finalized invoice.
capture!(:post, "#{draft_path}/void", headers: auth)

# 12. ANY update on a VOIDED invoice — the controller-level 405.
capture!(:patch, draft_path,
  headers: json,
  body: {invoice: {payment_status: "failed"}})

# 13. retry on a non-FAILED invoice — invalid_status envelope.
capture!(:post, "#{draft_path}/retry", headers: auth)

# 14. org-wide invoice index — one-off (created at CAPTURED_AT) first, the
#     seeded-and-finalized draft (created at SEEDED_AT) second.
capture!(:get, "/api/v1/invoices", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "invoice_actions capture complete: #{MANIFEST[:requests].length} requests"
