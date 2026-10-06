# frozen_string_literal: true

# Contract scenario: analytics_mrr — GET /api/v1/analytics/mrr.
#
# PREMIUM-GATED (Analytics::MrrsService → forbidden_failure!). Request #1
# captures the GATED 403 feature_unavailable envelope, then the scenario
# flips the License singleton (License.instance_variable_set(:@premium,
# true), same mechanism as the Rails suite's :premium specs) BEFORE request
# #2. The replay must mirror the flip between manifest requests #1 and #2.
#
# The Analytics::Mrr raw-SQL model normalizes subscription fees to a monthly
# run-rate over a generate_series capped by the DATABASE clock:
#
#   - monthly plans: one row per issuing month, full fee amount
#   - yearly/quarterly/semiannual ADVANCE plans: the fee is SPREAD across
#     CEIL(billed_months) months starting at the issuing month, first month
#     prorated (days-to-month-end / days-in-month)
#   - amount_cents = (fee.amount_cents - precise_coupons_amount_cents)
#                    + taxes_amount_cents; fee_type = 2 (subscription),
#                    invoice finalized, self_billed false, dispute-lost out
#
# SURPRISE for the replay slice: like invoice_collection, the outer query
# has NO `IS NOT NULL` filter — every month from the org's creation month
# to the current month returns a row, with amount_cents: null and
# currency: null when there is no data.
#
# SURPRISE 2: the fractional spread amounts are Postgres numerics; the
# serializer `&.to_i`s them, so months carry TRUNCATED cents (the spread
# does NOT sum back to the fee).
#
# SEEDED (not billed) invoices+fees: unlike the invoice_* scenarios there
# is NO in-process billing here — the analytics endpoint is a read path over
# fee rows, so the seed writes the subscription fees + finalized invoices
# directly (deterministic, no minted ids, no replay-side billing trips):
#   - monthly arrears plan 4900c: fees on invoices issued 2025-07-01,
#     2025-08-01, 2025-09-01
#   - yearly pay-in-advance plan 120000c: fee issued 2025-06-15 with
#     properties from/to spanning one year → billed_months 365/30.44 ≈
#     11.99, spread over 12 months (June 2025 … May 2026), first prorated
#
# Run via: SCRATCH_DB=lago_golden_analytics_mrr \
#            scripts/contract/capture.sh analytics_mrr

require "json"

# The :premium flip — see the header note.
License.instance_variable_set(:@premium, true)

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-0000000000a4"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000b4"
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

def subscription_fee(invoice:, subscription:, amount:, from:, to:, created_at:)
  FactoryBot.create(
    :fee,
    organization: invoice.organization,
    invoice: invoice,
    subscription: subscription,
    fee_type: :subscription,
    invoiceable_type: "Subscription",
    invoiceable_id: subscription.id,
    amount_cents: amount,
    precise_amount_cents: amount.to_f,
    amount_currency: "EUR",
    taxes_amount_cents: 0,
    taxes_precise_amount_cents: 0.0,
    precise_coupons_amount_cents: 0.0,
    created_at: created_at,
    properties: {
      "from_datetime" => from,
      "to_datetime" => to,
      "charges_from_datetime" => from,
      "charges_to_datetime" => to
    }
  )
end

def finalized_invoice(organization:, customer:, issuing_date:, total:)
  FactoryBot.create(
    :invoice,
    organization: organization,
    customer: customer,
    issuing_date: issuing_date,
    payment_due_date: issuing_date,
    total_amount_cents: total,
    currency: "EUR"
  )
end

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract MRR Org",
    slug: "contract-mrr-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "MRR Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: "mrr-cust-1",
    name: "MRR Customer",
    currency: "EUR"
  )

  plan_monthly = FactoryBot.create(
    :plan,
    organization: organization,
    name: "MRR Monthly Plan",
    code: "mrr-monthly",
    interval: "monthly",
    amount_cents: 4_900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  plan_yearly = FactoryBot.create(
    :plan,
    organization: organization,
    name: "MRR Yearly Plan",
    code: "mrr-yearly",
    interval: "yearly",
    amount_cents: 120_000,
    amount_currency: "EUR",
    pay_in_advance: true
  )

  subscription_monthly = FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan_monthly,
    external_id: "mrr-sub-monthly"
  )

  subscription_yearly = FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan_yearly,
    external_id: "mrr-sub-yearly"
  )

  # ---- monthly arrears: one fee per invoiced month (4900 each) -------------
  [
    [Date.new(2025, 7, 1), Time.utc(2025, 6, 1), Time.utc(2025, 7, 1), Time.utc(2025, 7, 1, 0, 0, 1)],
    [Date.new(2025, 8, 1), Time.utc(2025, 7, 1), Time.utc(2025, 8, 1), Time.utc(2025, 8, 1, 0, 0, 1)],
    [Date.new(2025, 9, 1), Time.utc(2025, 8, 1), Time.utc(2025, 9, 1), Time.utc(2025, 9, 1, 0, 0, 1)]
  ].each do |issuing, from, to, fee_created|
    invoice = finalized_invoice(
      organization: organization, customer: customer,
      issuing_date: issuing, total: 4_900
    )
    subscription_fee(
      invoice: invoice, subscription: subscription_monthly,
      amount: 4_900, from: from, to: to, created_at: fee_created
    )
  end

  # ---- yearly ADVANCE issued mid-June: spread over 12 months ---------------
  invoice_yearly = finalized_invoice(
    organization: organization, customer: customer,
    issuing_date: Date.new(2025, 6, 15), total: 120_000
  )
  subscription_fee(
    invoice: invoice_yearly, subscription: subscription_yearly,
    amount: 120_000,
    from: Time.utc(2025, 6, 15), to: Time.utc(2026, 6, 15),
    created_at: Time.utc(2025, 6, 15, 0, 0, 1)
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
  capture!(:get, "/api/v1/analytics/mrr", headers: auth)
end

# Premium ON for the data requests (see header).
License.instance_variable_set(:@premium, true)

travel_to(CAPTURED_AT) do
  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 2. unfiltered — every month since org creation, nulls where no data.
  capture!(:get, "/api/v1/analytics/mrr", headers: auth)

  # 3. currency filter.
  capture!(:get, "/api/v1/analytics/mrr?currency=EUR", headers: auth)

  # 4. months=24 — the seeded months fall inside the window.
  capture!(:get, "/api/v1/analytics/mrr?months=24", headers: auth)

  # 5. months=3 — relative to the DATABASE clock → three null months.
  capture!(:get, "/api/v1/analytics/mrr?months=3", headers: auth)
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "analytics_mrr capture complete: #{MANIFEST[:requests].length} requests (1 gated + 4 premium)"
