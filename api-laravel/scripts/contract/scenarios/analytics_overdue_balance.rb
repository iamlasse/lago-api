# frozen_string_literal: true

# Contract scenario: analytics_overdue_balance — GET /api/v1/analytics/overdue_balance.
#
# NOT premium-gated. The Analytics::OverdueBalance raw-SQL model groups
# payment_overdue invoices (self_billed IS FALSE, status != deleted) by
# month of payment_due_date + currency + billing entity and returns
#
#   amount_cents     = SUM(total_amount_cents - total_paid_amount_cents
#                          - finalized credit_notes.offset_amount_cents)
#   lago_invoice_ids = jsonb_agg of the invoice ids, flattened by the
#                      serializer (JSON.parse(...).flatten — note the ids are
#                      SEEDED ids here, so they compare strictly on replay)
#
# Only months with data are returned (the outer query filters
# `invs.total_amount_cents IS NOT NULL`), over a generate_series capped by
# the DATABASE clock — see the README analytics note.
#
# Seed layout:
#   2025-06 EUR: overdue 10000, paid 1000, credit-note offset 1500 → 7500
#   2025-06 USD: overdue 8000 → 8000
#   2025-07 EUR: overdue 5000 + 2500 → 7500 (two ids in one row)
#   excluded control: a fully-paid, non-overdue invoice (3000)
#
# Run via: SCRATCH_DB=lago_golden_analytics_overdue_balance \
#            scripts/contract/capture.sh analytics_overdue_balance

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-0000000000a2"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000b2"
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
    name: "Contract Overdue Org",
    slug: "contract-overdue-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Overdue Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer_one = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "overdue-cust-1",
    name: "Overdue Customer One",
    currency: "EUR"
  )

  customer_two = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "overdue-cust-2",
    name: "Overdue Customer Two",
    currency: "EUR"
  )

  # ---- 2025-06 EUR: 10000 - 1000 paid - 1500 offset = 7500 -----------------
  invoice_june_overdue = FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000111",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 1),
    payment_due_date: Date.new(2025, 6, 15),
    total_amount_cents: 10_000,
    total_paid_amount_cents: 1_000,
    payment_overdue: true,
    currency: "EUR"
  )

  FactoryBot.create(
    :credit_note,
    organization: organization,
    customer: customer_one,
    invoice: invoice_june_overdue,
    total_amount_cents: 1_500,
    offset_amount_cents: 1_500,
    offset_amount_currency: "EUR"
  ) # status defaults to finalized → counted in the offset subtraction

  # ---- 2025-06 USD: 8000 ----------------------------------------------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000112",
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 6, 10),
    payment_due_date: Date.new(2025, 6, 20),
    total_amount_cents: 8_000,
    payment_overdue: true,
    currency: "USD"
  )

  # ---- 2025-07 EUR: 5000 + 2500 = 7500, two ids in one row -----------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000113",
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 7, 2),
    payment_due_date: Date.new(2025, 7, 20),
    total_amount_cents: 5_000,
    payment_overdue: true,
    currency: "EUR"
  )

  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000114",
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 7, 8),
    payment_due_date: Date.new(2025, 7, 31),
    total_amount_cents: 2_500,
    payment_overdue: true,
    currency: "EUR"
  )

  # ---- excluded control: fully paid, NOT overdue ---------------------------
  FactoryBot.create(
    :invoice,
    id: "1a4a0d6e-0000-4000-8000-000000000115",
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 12),
    payment_due_date: Date.new(2025, 6, 12),
    total_amount_cents: 3_000,
    total_paid_amount_cents: 3_000,
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

  # 1. unfiltered.
  capture!(:get, "/api/v1/analytics/overdue_balance", headers: auth)

  # 2. customer filter.
  capture!(:get, "/api/v1/analytics/overdue_balance?external_customer_id=overdue-cust-1", headers: auth)

  # 3. currency filter.
  capture!(:get, "/api/v1/analytics/overdue_balance?currency=EUR", headers: auth)

  # 4. months=3 — relative to the DATABASE clock → empty (README note).
  capture!(:get, "/api/v1/analytics/overdue_balance?months=3", headers: auth)

  # 5. months=24 — includes the 2025 data.
  capture!(:get, "/api/v1/analytics/overdue_balance?months=24", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "analytics_overdue_balance capture complete: #{MANIFEST[:requests].length} requests"
