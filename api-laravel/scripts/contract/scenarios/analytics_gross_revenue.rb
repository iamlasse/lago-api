# frozen_string_literal: true

# Contract scenario: analytics_gross_revenue — GET /api/v1/analytics/gross_revenue.
#
# NOT premium-gated (Analytics::GrossRevenuesService has no License check —
# unlike mrr/invoiced_usage/invoice_collection). The service runs the
# Analytics::GrossRevenue raw-SQL model directly on Postgres:
#
#   - one row per (month, currency, billing_entity_id) that HAS data, over a
#     generate_series from the org's creation month to the CURRENT MONTH —
#     the series is capped by `am.month <= DATE_TRUNC('month', CURRENT_DATE)`,
#     i.e. the DATABASE clock, NOT travel_to (see the README analytics note);
#   - revenue = finalized invoices (status = 1, self_billed IS FALSE,
#     payment_dispute_lost_at IS NULL) MINUS their finalized credit notes'
#     refund_amount_cents, PLUS un-invoiced pay_in_advance fees (instant
#     charges: fees with invoice_id IS NULL, pay_in_advance IS TRUE);
#   - drafts, voided and dispute-lost invoices are excluded (seeded as
#     negative controls).
#
# The seed layout (data months 2025-06 and 2025-07):
#   2025-06 EUR default BE:  invoices 10000 + 5000 + 8000 (capture-be entity
#                            keeps its own row), refund 2000 on the first
#   2025-06 USD:             2000
#   2025-07 EUR:             invoice 7000 + instant charge fee 1500
# Excluded controls: a draft invoice (9999) and a dispute-lost invoice (4242).
#
# Run via: SCRATCH_DB=lago_golden_analytics_gross_revenue \
#            scripts/contract/capture.sh analytics_gross_revenue

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-0000000000a1"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000b1"
CAPTURED_AT = Time.utc(2025, 6, 12, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {})
  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: path,
    headers: headers, body: body, required_headers: required_headers
  }

  SESSION.public_send(verb, path, headers: headers)

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
    name: "Contract Gross Revenue Org",
    slug: "contract-gross-revenue-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Gross Revenue Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer_one = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "grossrev-cust-1",
    name: "Gross Revenue Customer One",
    currency: "EUR"
  )

  customer_two = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "grossrev-cust-2",
    name: "Gross Revenue Customer Two",
    currency: "EUR"
  )

  # A SECOND billing entity with a known code, so the billing_entity_code
  # filter has its own row (the org factory's default billing entity has a
  # random code; its id is random too but frozen inside the fixture).
  capture_entity = FactoryBot.create(
    :billing_entity,
    organization: organization,
    name: "Capture Entity",
    code: "capture-be"
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Gross Revenue Plan",
    code: "grossrev-plan",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: true
  )

  subscription = FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer_one,
    plan: plan,
    external_id: "grossrev-sub-1"
  )

  # ---- 2025-06 EUR (default billing entity): 10000 + 5000 - 2000 refund ----
  invoice_june_one = FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000101",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 5),
    payment_due_date: Date.new(2025, 6, 5),
    total_amount_cents: 10_000,
    currency: "EUR"
  )

  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000102",
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 6, 20),
    payment_due_date: Date.new(2025, 6, 20),
    total_amount_cents: 5_000,
    currency: "EUR"
  )

  FactoryBot.create(
    :credit_note,
    organization: organization,
    customer: customer_one,
    invoice: invoice_june_one,
    total_amount_cents: 2_000,
    refund_amount_cents: 2_000,
    refund_amount_currency: "EUR"
  ) # status defaults to finalized → counted in the refund subtraction

  # ---- 2025-06 EUR on the capture-be entity: its own row (8000) ------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000103",
    organization: organization,
    customer: customer_one,
    billing_entity: capture_entity,
    issuing_date: Date.new(2025, 6, 18),
    payment_due_date: Date.new(2025, 6, 18),
    total_amount_cents: 8_000,
    currency: "EUR"
  )

  # ---- 2025-06 USD: 2000 ----------------------------------------------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000104",
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 6, 25),
    payment_due_date: Date.new(2025, 6, 25),
    total_amount_cents: 2_000,
    currency: "USD"
  )

  # ---- 2025-07 EUR: invoice 7000 + instant charge fee 1500 ------------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000105",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 7, 10),
    payment_due_date: Date.new(2025, 7, 10),
    total_amount_cents: 7_000,
    currency: "EUR"
  )

  # Instant charge: an un-invoiced pay_in_advance fee (invoice_id IS NULL).
  # The fee factory requires an invoice association — null it out afterwards,
  # keeping organization / billing_entity / subscription joins intact.
  instant_fee = FactoryBot.create(
    :fee,
    organization: organization,
    invoice: nil,
    subscription: subscription,
    pay_in_advance: true,
    amount_cents: 1_500,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    created_at: Time.utc(2025, 7, 8, 9, 0, 0)
  )
  instant_fee.update_columns(invoice_id: nil)

  # ---- excluded controls -----------------------------------------------------
  FactoryBot.create( # draft: status != 1
    :invoice,
    :draft,
    id: "1a4a0d6e-0000-4000-8000-000000000106",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 28),
    payment_due_date: Date.new(2025, 6, 28),
    total_amount_cents: 9_999,
    currency: "EUR"
  )

  FactoryBot.create( # dispute lost: payment_dispute_lost_at IS NOT NULL
    :invoice,
    :dispute_lost,
    id: "1a4a0d6e-0000-4000-8000-000000000107",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 7, 15),
    payment_due_date: Date.new(2025, 7, 15),
    total_amount_cents: 4_242,
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

  # 1. unfiltered — rows only for months/currencies/entities WITH data.
  capture!(:get, "/api/v1/analytics/gross_revenue", headers: auth)

  # 2. currency filter, lowercase — the controller upcases it; the SQL
  #    COALESCEs cd.currency against the requested one.
  capture!(:get, "/api/v1/analytics/gross_revenue?currency=eur", headers: auth)

  # 3. currency filter, already-uppercase USD.
  capture!(:get, "/api/v1/analytics/gross_revenue?currency=USD", headers: auth)

  # 4. external customer filter (also drops the fee's instant charge of
  #    customer two? — customer two has no instant fee; both EUR June
  #    invoices of cust 1 minus its refund remain).
  capture!(:get, "/api/v1/analytics/gross_revenue?external_customer_id=grossrev-cust-1", headers: auth)

  # 5. billing entity filter by code — only the capture-be row.
  capture!(:get, "/api/v1/analytics/gross_revenue?billing_entity_code=capture-be", headers: auth)

  # 6. months=3 — RELATIVE TO THE DATABASE CLOCK (CURRENT_DATE), not the
  #    frozen clock: with capture data in 2025-06/07 the window is empty.
  #    This golden PINS that behavior — see the README analytics note.
  capture!(:get, "/api/v1/analytics/gross_revenue?months=3", headers: auth)

  # 7. months=24 — wide enough to include the 2025 data from any 2026 clock.
  capture!(:get, "/api/v1/analytics/gross_revenue?months=24", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "analytics_gross_revenue capture complete: #{MANIFEST[:requests].length} requests"
