# frozen_string_literal: true

# Contract scenario: invoice_graduated — a subscription invoice whose ONLY
# usage fee is a GRADUATED charge, billed in arrears over a full calendar
# month, so the golden carries the fee's `amount_details.graduated_ranges`
# (one entry per tier the aggregation touched: flat_unit_amount,
# per_unit_total_amount, total_with_flat_amount, units).
#
# The billing itself is NOT an HTTP call (Rails bills via BillSubscriptionJob
# from the clock), so the capture triggers it IN-PROCESS, exactly like
# production does:
#
#   1. SEEDED_AT  — org, api key, customer, sum metric, graduated plan, and
#      three Event rows (seeded via the :event factory, deterministic
#      transaction ids, `timestamp` inside May). Seeded events ride the
#      fixture.sql to the replay, so both runtimes aggregate over identical
#      input without either side's events endpoint.
#   2. request #1 — POST /subscriptions (arrears, calendar, subscription_at
#      2025-05-01) — no invoice yet.
#   3. BILLING_AT — travel_to(2025-06-01T00:00Z) +
#      BillSubscriptionJob.perform_now([subscription], BILLING_AT.to_i,
#      invoicing_reason: :subscription_periodic). The job is the production
#      entry point; perform_now just runs it inline instead of via Sidekiq.
#      This instant is the invoice's created_at/issuing clock on BOTH sides
#      (the Laravel test freezes the same instant around its own
#      BillSubscriptionJob::handle()).
#   4. request #2 — GET /invoices?external_customer_id=… (the minted invoice,
#     summarized), then EXTRA golden 10.json: GET /invoices/<minted id> —
#     the full invoice incl. fees[].amount_details. The id is minted by the
#     in-process job, so it can't be in the manifest; the test substitutes
#     the id its own replay minted (see InvoiceGraduatedTest).
#
# Fixture values come from Rails' spec/services/charge_models/
# graduated_service_spec.rb: ranges (0..10 @10+2), (11..20 @5+3),
# (21..∞ @5+3) and an aggregation of 21 → expected amount 163 (100 + 2 flat,
# 50 + 3 flat, 5 + 3 flat).
#
# Run via scripts/contract/capture.sh invoice_graduated.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000051"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000052"
CHARGE_ID = "1a4a0d6e-0000-4000-8000-000000000053"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-00000000005e"
CUSTOMER_EXTERNAL_ID = "graduated-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "graduated-sub-1"
CAPTURED_AT = Time.utc(2025, 5, 2, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
SUBSCRIPTION_AT = Time.utc(2025, 5, 1, 0, 0, 0)
BILLING_AT = Time.utc(2025, 6, 1, 0, 0, 0)

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

travel_to(SEEDED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Graduated Org",
    slug: "contract-graduated-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Graduated Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Graduated Customer Co.",
    email: "graduated@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "7 Rue des Paliers",
    zipcode: "75005",
    state: "IDF",
    timezone: nil
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Tiered API Calls",
    code: "tiered_calls",
    description: "seeded sum metric for the graduated charge",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Graduated Plan",
    code: "graduated-plan",
    invoice_display_name: "Graduated Plan Display",
    description: "arrears plan with a graduated charge",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :graduated_charge,
    id: CHARGE_ID,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "graduated-charge",
    invoice_display_name: "Tiered API calls",
    properties: {
      graduated_ranges: [
        {from_value: 0, to_value: 10, per_unit_amount: "10", flat_amount: "2"},
        {from_value: 11, to_value: 20, per_unit_amount: "5", flat_amount: "3"},
        {from_value: 21, to_value: nil, per_unit_amount: "5", flat_amount: "3"}
      ]
    }
  )

  # Metered input for the aggregation, seeded straight into `events` (the
  # production writer of that table is the events endpoint's ingest job —
  # not part of this slice). Sum over May: 10 + 10 + 1 = 21 units → tier 1
  # full + tier 2 full + tier 3 one unit = 163.
  {
    "tr-graduated-1" => [Time.utc(2025, 5, 10, 9, 0, 0), 10],
    "tr-graduated-2" => [Time.utc(2025, 5, 11, 9, 0, 0), 10],
    "tr-graduated-3" => [Time.utc(2025, 5, 14, 9, 0, 0), 1]
  }.each do |transaction_id, (timestamp, value)|
    FactoryBot.create(
      :event,
      organization_id: organization.id,
      transaction_id: transaction_id,
      code: "tiered_calls",
      timestamp: timestamp,
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      properties: {"calls" => value}
    )
  end

  # NOTE: the Laravel port's aggregation seam (see
  # app/Services/Fees/ChargeService/Aggregator.php) reads the
  # `cached_aggregations` table instead of querying `events` — live event
  # aggregation is M2 there. Rails IGNORES cached rows on the arrears
  # periodic path (they are only read for pay-in-advance event billing), so
  # this row is invisible to the Rails capture while carrying the SAME
  # aggregation input (21) to the Laravel replay. It is the honest carrier
  # of metered state for the seam, not a way to fake the golden: everything
  # downstream (tiering math, amount_details) still has to match.
  FactoryBot.create(
    :cached_aggregation,
    organization: organization,
    charge_id: CHARGE_ID,
    external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
    charge_filter_id: nil,
    grouped_by: {},
    timestamp: Time.utc(2025, 5, 14, 9, 0, 0),
    current_aggregation: 21,
    max_aggregation: 21
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

  # 1. arrears subscription starting at the month boundary — nothing is
  #    billed at creation (pay_in_advance: false on the plan).
  capture!(:post, "/api/v1/subscriptions",
    headers: json,
    body: {subscription: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      plan_code: "graduated-plan",
      external_id: SUBSCRIPTION_EXTERNAL_ID,
      name: "Graduated Sub",
      billing_time: "calendar",
      subscription_at: SUBSCRIPTION_AT.iso8601
    }})
end

# --- IN-PROCESS BILLING at the June boundary (not an HTTP contract —
#     production bills from the clock; see the header comment).
#
# Rails' TimeHelpers refuse NESTED travel_to blocks, so billing runs in its
# own top-level travel: freeze the clock at the June boundary (the invoice's
# created_at / issuing clock on BOTH sides — the Laravel test freezes the
# same instant around its own BillSubscriptionJob) and run the production
# billing entry point inline (perform_now = run now, not via Sidekiq).
subscription = Subscription.find_by(external_id: SUBSCRIPTION_EXTERNAL_ID)
travel_to(BILLING_AT) do
  BillSubscriptionJob.perform_now(
    [subscription], BILLING_AT.to_i, invoicing_reason: :subscription_periodic
  )
end

travel_to(CAPTURED_AT) do
  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 2. invoice index — the minted invoice, summarized.
  capture!(:get, "/api/v1/invoices?external_customer_id=#{CUSTOMER_EXTERNAL_ID}", headers: auth)

  # EXTRA golden 10.json (not in the manifest): show the invoice the
  # in-process billing minted, addressed by ITS id. The replay substitutes
  # the id its own billing minted — see InvoiceGraduatedTest.
  invoice_id = Invoice.find_by(customer: subscription.customer).id
  capture!(:get, "/api/v1/invoices/#{invoice_id}", headers: auth, golden_name: "10")
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "invoice_graduated capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
