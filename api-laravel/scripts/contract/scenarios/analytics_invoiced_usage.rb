# frozen_string_literal: true

# Contract scenario: analytics_invoiced_usage — GET /api/v1/analytics/invoiced_usage.
#
# PREMIUM-GATED (Analytics::InvoicedUsagesService → forbidden_failure!).
# Request #1 captures the GATED 403 feature_unavailable envelope, then the
# scenario flips the License singleton (License.instance_variable_set(
# :@premium, true), same mechanism as the Rails suite's :premium specs)
# BEFORE request #2. The replay must mirror the flip between manifest
# requests #1 and #2.
#
# The Analytics::InvoicedUsage raw-SQL model groups CHARGE fees by month of
# fee.created_at (NOT invoice issuing_date!), billable-metric code (via the
# fee's charge) and currency:
#
#   amount_cents = SUM(amount_cents - precise_coupons_amount_cents)
#   fee_type = 0 (charge), invoiceable_type = 'Charge',
#   invoice finalized-enough: self_billed IS FALSE, dispute-lost excluded
#
# Only months with data are returned (outer `IS NOT NULL` filters), ordered
# `am.month DESC, trpmb.amount_cents DESC` — newest month first, and within
# a month the bigger metric first.
#
# Seed layout (EUR, all on one customer's invoices):
#   2025-06: api_calls 5000 + (3000 - 500 coupon) = 7500, storage_gb 1200
#   2025-07: api_calls 7000
#
# Run via: SCRATCH_DB=lago_golden_analytics_invoiced_usage \
#            scripts/contract/capture.sh analytics_invoiced_usage

require "json"

# The :premium flip — see the header note.
License.instance_variable_set(:@premium, true)

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-0000000000a5"
METRIC_CALLS_ID = "1a4a0d6e-0000-4000-8000-0000000000a6"
METRIC_STORAGE_ID = "1a4a0d6e-0000-4000-8000-0000000000a7"
CHARGE_CALLS_ID = "1a4a0d6e-0000-4000-8000-0000000000a8"
CHARGE_STORAGE_ID = "1a4a0d6e-0000-4000-8000-0000000000a9"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000b5"
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
    name: "Contract Invoiced Usage Org",
    slug: "contract-invoiced-usage-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Invoiced Usage Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "invoiced-usage-cust-1",
    name: "Invoiced Usage Customer",
    currency: "EUR"
  )

  metric_calls = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_CALLS_ID,
    organization: organization,
    name: "API Calls",
    code: "api_calls",
    field_name: "calls"
  )

  metric_storage = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_STORAGE_ID,
    organization: organization,
    name: "Storage GB",
    code: "storage_gb",
    field_name: "gigabytes"
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Invoiced Usage Plan",
    code: "invoiced-usage-plan",
    interval: "monthly",
    amount_cents: 4_900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  charge_calls = FactoryBot.create(
    :standard_charge,
    id: CHARGE_CALLS_ID,
    organization: organization,
    plan: plan,
    billable_metric: metric_calls,
    code: "api-calls-charge",
    properties: {amount: "100"}
  )

  charge_storage = FactoryBot.create(
    :standard_charge,
    id: CHARGE_STORAGE_ID,
    organization: organization,
    plan: plan,
    billable_metric: metric_storage,
    code: "storage-charge",
    properties: {amount: "50"}
  )

  subscription = FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan,
    external_id: "invoiced-usage-sub-1"
  )

  # ---- 2025-06 invoice carrying three charge fees ---------------------------
  invoice_june = FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer,
    issuing_date: Date.new(2025, 6, 30),
    payment_due_date: Date.new(2025, 6, 30),
    total_amount_cents: 9_200,
    currency: "EUR"
  )

  FactoryBot.create(
    :charge_fee,
    organization: organization,
    invoice: invoice_june,
    subscription: subscription,
    charge: charge_calls,
    amount_cents: 5_000,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    precise_coupons_amount_cents: 0.0,
    created_at: Time.utc(2025, 6, 10, 8, 0, 0)
  )

  FactoryBot.create(
    :charge_fee,
    organization: organization,
    invoice: invoice_june,
    subscription: subscription,
    charge: charge_calls,
    amount_cents: 3_000,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    precise_coupons_amount_cents: 500.0, # subtracted by the analytics SQL
    created_at: Time.utc(2025, 6, 20, 8, 0, 0)
  )

  FactoryBot.create(
    :charge_fee,
    organization: organization,
    invoice: invoice_june,
    subscription: subscription,
    charge: charge_storage,
    amount_cents: 1_200,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    precise_coupons_amount_cents: 0.0,
    created_at: Time.utc(2025, 6, 25, 8, 0, 0)
  )

  # ---- 2025-07 invoice carrying one charge fee -------------------------------
  invoice_july = FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer,
    issuing_date: Date.new(2025, 7, 31),
    payment_due_date: Date.new(2025, 7, 31),
    total_amount_cents: 7_000,
    currency: "EUR"
  )

  FactoryBot.create(
    :charge_fee,
    organization: organization,
    invoice: invoice_july,
    subscription: subscription,
    charge: charge_calls,
    amount_cents: 7_000,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    precise_coupons_amount_cents: 0.0,
    created_at: Time.utc(2025, 7, 5, 8, 0, 0)
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

  # 1. GATED: flip back OFF for the captured envelope (the OSS contract).
  License.instance_variable_set(:@premium, false)
  capture!(:get, "/api/v1/analytics/invoiced_usage", headers: auth)
end

# Premium ON for the data requests (see header).
License.instance_variable_set(:@premium, true)

travel_to(CAPTURED_AT) do
  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 2. unfiltered — newest month first, per-month amount DESC.
  capture!(:get, "/api/v1/analytics/invoiced_usage", headers: auth)

  # 3. currency filter.
  capture!(:get, "/api/v1/analytics/invoiced_usage?currency=EUR", headers: auth)

  # 4. months=24 — the seeded months fall inside the window.
  capture!(:get, "/api/v1/analytics/invoiced_usage?months=24", headers: auth)

  # 5. months=3 — relative to the DATABASE clock → empty array.
  capture!(:get, "/api/v1/analytics/invoiced_usage?months=3", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "analytics_invoiced_usage capture complete: #{MANIFEST[:requests].length} requests (1 gated + 4 premium)"
