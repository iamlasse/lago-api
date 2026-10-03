# frozen_string_literal: true

# Contract scenario: invoice_percentage — a subscription invoice whose ONLY
# usage fee is a PERCENTAGE charge, billed in arrears over a full calendar
# month, so the golden carries the fee's
# `amount_details.{units,free_units,paid_units,free_events,paid_events,rate,
# per_unit_total_amount,fixed_fee_unit_amount,fixed_fee_total_amount,
# min_max_adjustment_total_amount}` — the free-units split needs the
# aggregation's running_total and event count, the deepest aggregation
# contract of the five models.
#
# Same flow as invoice_graduated.rb (read its header for the in-process
# billing rationale — SEEDED_AT seed incl. events + one cached_aggregations
# row, request #1 subscription create, travel_to(BILLING_AT) +
# BillSubscriptionJob.perform_now, request #2 invoice index, EXTRA golden
# 10.json = invoice show with the minted id, replayed against the id the
# replay's own billing minted — see InvoicePercentageTest).
#
# Fixture values from Rails' spec/services/charge_models/percentage_service_spec.rb:
# rate "1.3", fixed_amount "2.0", free_units_per_events 3,
# free_units_per_total_aggregation "250.0"; four May events of 200 each →
# sum 800, count 4.
#
# Run via scripts/contract/capture.sh invoice_percentage.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000071"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000072"
CHARGE_ID = "1a4a0d6e-0000-4000-8000-000000000073"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-00000000007e"
CUSTOMER_EXTERNAL_ID = "percentage-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "percentage-sub-1"
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
    name: "Contract Percentage Org",
    slug: "contract-percentage-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Percentage Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Percentage Customer Co.",
    email: "percentage@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "11 Rue des Pourcentages",
    zipcode: "75011",
    state: "IDF",
    timezone: nil
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Charged API Calls",
    code: "charged_calls",
    description: "seeded sum metric for the percentage charge",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Percentage Plan",
    code: "percentage-plan",
    invoice_display_name: "Percentage Plan Display",
    description: "arrears plan with a percentage charge",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :percentage_charge,
    id: CHARGE_ID,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "percentage-charge",
    invoice_display_name: "Charged API calls",
    properties: {
      rate: "1.3",
      fixed_amount: "2.0",
      free_units_per_events: 3,
      free_units_per_total_aggregation: "250.0",
      per_transaction_max_amount: nil,
      per_transaction_min_amount: nil
    }
  )

  # Metered input, seeded straight into `events` (see invoice_graduated.rb).
  # Sum over May: 4 events x 200 = 800 units across 4 events — the
  # free_units_per_events=3 split and free_units_per_total_aggregation=250
  # both bite.
  {
    "tr-percentage-1" => [Time.utc(2025, 5, 10, 9, 0, 0), 200],
    "tr-percentage-2" => [Time.utc(2025, 5, 11, 9, 0, 0), 200],
    "tr-percentage-3" => [Time.utc(2025, 5, 12, 9, 0, 0), 200],
    "tr-percentage-4" => [Time.utc(2025, 5, 14, 9, 0, 0), 200]
  }.each do |transaction_id, (timestamp, value)|
    FactoryBot.create(
      :event,
      organization_id: organization.id,
      transaction_id: transaction_id,
      code: "charged_calls",
      timestamp: timestamp,
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      properties: {"calls" => value}
    )
  end

  # The Laravel aggregation seam's input carrier — same sum as the events;
  # invisible to Rails on the arrears periodic path (invoice_graduated.rb
  # documents this in full). NOTE: the seam cannot carry the EVENT COUNT
  # (4) or the per-event running_total [200,400,600,800] — whatever the
  # replay makes of free_events/paid_events is part of the finding.
  FactoryBot.create(
    :cached_aggregation,
    organization: organization,
    charge_id: CHARGE_ID,
    external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
    charge_filter_id: nil,
    grouped_by: {},
    timestamp: Time.utc(2025, 5, 14, 9, 0, 0),
    current_aggregation: 800,
    max_aggregation: 800
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

  json = {"Content-Type" => "application/json",
          "Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 1. arrears subscription starting at the month boundary.
  capture!(:post, "/api/v1/subscriptions",
    headers: json,
    body: {subscription: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      plan_code: "percentage-plan",
      external_id: SUBSCRIPTION_EXTERNAL_ID,
      name: "Percentage Sub",
      billing_time: "calendar",
      subscription_at: SUBSCRIPTION_AT.iso8601
    }})
end

# --- IN-PROCESS BILLING at the June boundary — see invoice_graduated.rb
#     (Rails' TimeHelpers refuse nested travel_to, hence the top-level trip).
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

  # EXTRA golden 10.json — the full invoice with fees[].amount_details.
  invoice_id = Invoice.find_by(customer: subscription.customer).id
  capture!(:get, "/api/v1/invoices/#{invoice_id}", headers: auth, golden_name: "10")
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "invoice_percentage capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
