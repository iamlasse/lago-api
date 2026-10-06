# frozen_string_literal: true

# Contract scenario: analytics_invoice_collection — GET /api/v1/analytics/invoice_collection.
#
# PREMIUM-GATED: Analytics::InvoiceCollectionsService returns
# forbidden_failure! (403 feature_unavailable) unless License.premium?. The
# OSS capture image has no license, so request #1 captures the GATED
# envelope — that envelope IS the contract for the OSS capture stack — and
# the scenario then flips the License singleton the way the Rails suite's
# own :premium specs do (spec/support/license_helper.rb:
# `License.instance_variable_set(:@premium, true)`) BEFORE request #2. The
# replay must mirror the flip between manifest requests #1 and #2 (Laravel:
# `config(['lago.license' => ...])` mid-test, as in credit_notes_lifecycle).
#
# The Analytics::InvoiceCollection raw-SQL model groups FINALIZED invoices
# (status = 1, self_billed IS FALSE, dispute-lost excluded) by month of
# issuing_date + payment_status (pending/succeeded/failed) + currency.
#
# SURPRISE for the replay slice: the outer query has NO `IS NOT NULL`
# filter — unlike gross_revenue/overdue_balance/invoiced_usage it returns a
# row for EVERY month from the org's creation month to the current
# DATABASE-clock month, with payment_status: null, invoices_count: 0,
# amount_cents: 0 for months without invoices. Golden 2.json is one
# ~17-month series where only two months carry data. Empty months are part
# of the contract.
#
# Run via: SCRATCH_DB=lago_golden_analytics_invoice_collection \
#            scripts/contract/capture.sh analytics_invoice_collection

require "json"

# The premium flip happens BETWEEN manifest requests #1 (gated) and #2
# (premium) — see below. Same mechanism as invoice_graduated_percentage.rb.

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-0000000000a3"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000b3"
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
    name: "Contract Collection Org",
    slug: "contract-collection-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Collection Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer_one = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "col-cust-1",
    name: "Collection Customer One",
    currency: "EUR"
  )

  customer_two = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "col-cust-2",
    name: "Collection Customer Two",
    currency: "EUR"
  )

  # ---- 2025-06 EUR: one pending + one succeeded -----------------------------
  FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 5),
    payment_due_date: Date.new(2025, 6, 5),
    total_amount_cents: 4_000,
    payment_status: :pending,
    currency: "EUR"
  )

  FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 15),
    payment_due_date: Date.new(2025, 6, 15),
    total_amount_cents: 6_000,
    payment_status: :succeeded,
    currency: "EUR"
  )

  # ---- 2025-06 USD: one succeeded -------------------------------------------
  FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 6, 25),
    payment_due_date: Date.new(2025, 6, 25),
    total_amount_cents: 1_500,
    payment_status: :succeeded,
    currency: "USD"
  )

  # ---- 2025-07 EUR: one failed -----------------------------------------------
  FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer_two,
    issuing_date: Date.new(2025, 7, 2),
    payment_due_date: Date.new(2025, 7, 2),
    total_amount_cents: 2_500,
    payment_status: :failed,
    currency: "EUR"
  )

  # ---- excluded control: draft is status 0, not 1 ----------------------------
  FactoryBot.create(
    :invoice,
    :draft,
    organization: organization,
    customer: customer_one,
    issuing_date: Date.new(2025, 6, 28),
    payment_due_date: Date.new(2025, 6, 28),
    total_amount_cents: 9_999,
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

  # 1. GATED: flip back OFF for the captured envelope, so the golden shows
  #    exactly what an OSS (license-less) deployment answers.
  License.instance_variable_set(:@premium, false)
  capture!(:get, "/api/v1/analytics/invoice_collection", headers: auth)
end

# Premium ON for the data requests (see header).
License.instance_variable_set(:@premium, true)

travel_to(CAPTURED_AT) do
  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 2. unfiltered — includes the empty-month series (see header surprise).
  capture!(:get, "/api/v1/analytics/invoice_collection", headers: auth)

  # 3. currency filter.
  capture!(:get, "/api/v1/analytics/invoice_collection?currency=EUR", headers: auth)

  # 4. customer filter.
  capture!(:get, "/api/v1/analytics/invoice_collection?external_customer_id=col-cust-1", headers: auth)

  # 5. months=3 — relative to the DATABASE clock → three empty months.
  capture!(:get, "/api/v1/analytics/invoice_collection?months=3", headers: auth)

  # 6. months=24 — the data months fall inside the window.
  capture!(:get, "/api/v1/analytics/invoice_collection?months=24", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "analytics_invoice_collection capture complete: #{MANIFEST[:requests].length} requests (1 gated + 5 premium)"
