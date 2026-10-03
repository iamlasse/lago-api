# frozen_string_literal: true

# Contract scenario: invoice_volume — a subscription invoice whose ONLY
# usage fee is a VOLUME charge, billed in arrears over a full calendar
# month, so the golden carries the fee's
# `amount_details.{flat_unit_amount,per_unit_amount,per_unit_total_amount}`
# (volume charges the TOTAL aggregation in ONE range, unlike graduated).
#
# Same flow as invoice_graduated.rb (read its header for the in-process
# billing rationale — SEEDED_AT seed incl. events + one cached_aggregations
# row, request #1 subscription create, travel_to(BILLING_AT) +
# BillSubscriptionJob.perform_now, request #2 invoice index, EXTRA golden
# 10.json = invoice show with the minted id, replayed against the id the
# replay's own billing minted — see InvoiceVolumeTest).
#
# Fixture values from Rails' spec/services/charge_models/volume_service_spec.rb:
# ranges (0..100 @2+10), (101..200 @1+0), (201..∞ @0.5+50); three May events
# summing 250 units → third tier → 250 x 0.5 + 50 = 175.
#
# Run via scripts/contract/capture.sh invoice_volume.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000081"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000082"
CHARGE_ID = "1a4a0d6e-0000-4000-8000-000000000083"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-00000000008e"
CUSTOMER_EXTERNAL_ID = "volume-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "volume-sub-1"
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
    name: "Contract Volume Org",
    slug: "contract-volume-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Volume Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Volume Customer Co.",
    email: "volume@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "13 Rue des Volumes",
    zipcode: "75013",
    state: "IDF",
    timezone: nil
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Bulk API Calls",
    code: "bulk_calls",
    description: "seeded sum metric for the volume charge",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Volume Plan",
    code: "volume-plan",
    invoice_display_name: "Volume Plan Display",
    description: "arrears plan with a volume charge",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :volume_charge,
    id: CHARGE_ID,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "volume-charge",
    invoice_display_name: "Bulk API calls",
    properties: {
      volume_ranges: [
        {from_value: 0, to_value: 100, per_unit_amount: "2", flat_amount: "10"},
        {from_value: 101, to_value: 200, per_unit_amount: "1", flat_amount: "0"},
        {from_value: 201, to_value: nil, per_unit_amount: "0.5", flat_amount: "50"}
      ]
    }
  )

  # Metered input, seeded straight into `events` (see invoice_graduated.rb).
  # Sum over May: 100 + 100 + 50 = 250 units → third volume range.
  {
    "tr-volume-1" => [Time.utc(2025, 5, 10, 9, 0, 0), 100],
    "tr-volume-2" => [Time.utc(2025, 5, 11, 9, 0, 0), 100],
    "tr-volume-3" => [Time.utc(2025, 5, 14, 9, 0, 0), 50]
  }.each do |transaction_id, (timestamp, value)|
    FactoryBot.create(
      :event,
      organization_id: organization.id,
      transaction_id: transaction_id,
      code: "bulk_calls",
      timestamp: timestamp,
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
      properties: {"calls" => value}
    )
  end

  # The Laravel aggregation seam's input carrier — same sum as the events;
  # invisible to Rails on the arrears periodic path (invoice_graduated.rb
  # documents this in full).
  FactoryBot.create(
    :cached_aggregation,
    organization: organization,
    charge_id: CHARGE_ID,
    external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
    charge_filter_id: nil,
    grouped_by: {},
    timestamp: Time.utc(2025, 5, 14, 9, 0, 0),
    current_aggregation: 250,
    max_aggregation: 250
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
      plan_code: "volume-plan",
      external_id: SUBSCRIPTION_EXTERNAL_ID,
      name: "Volume Sub",
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
puts "invoice_volume capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
