# frozen_string_literal: true

# Contract scenario: invoice_package — a subscription invoice whose ONLY
# usage fee is a PACKAGE charge, billed in arrears over a full calendar
# month, so the golden carries the fee's
# `amount_details.{free_units,paid_units,per_package_size,
# per_package_unit_amount}`.
#
# Same flow as invoice_graduated.rb (read its header for the in-process
# billing rationale — SEEDED_AT seed incl. events + one cached_aggregations
# row, request #1 subscription create, travel_to(BILLING_AT) +
# BillSubscriptionJob.perform_now, request #2 invoice index, EXTRA golden
# 10.json = invoice show with the minted id, replayed against the id the
# replay's own billing minted — see InvoicePackageTest).
#
# Fixture values from Rails' spec/services/charge_models/package_service_spec.rb:
# amount "100", package_size 10, free_units 10, aggregation 121 → 111 paid
# units → 12 whole packages → 1200.
#
# Run via scripts/contract/capture.sh invoice_package.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000061"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000062"
CHARGE_ID = "1a4a0d6e-0000-4000-8000-000000000063"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-00000000006e"
CUSTOMER_EXTERNAL_ID = "package-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "package-sub-1"
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
    name: "Contract Package Org",
    slug: "contract-package-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Package Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Package Customer Co.",
    email: "package@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "9 Rue des Forfaits",
    zipcode: "75009",
    state: "IDF",
    timezone: nil
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Packaged API Calls",
    code: "packaged_calls",
    description: "seeded sum metric for the package charge",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Package Plan",
    code: "package-plan",
    invoice_display_name: "Package Plan Display",
    description: "arrears plan with a package charge",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :package_charge,
    id: CHARGE_ID,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "package-charge",
    invoice_display_name: "Packaged API calls",
    properties: {
      amount: "100",
      package_size: 10,
      free_units: 10
    }
  )

  # Metered input, seeded straight into `events` (see invoice_graduated.rb).
  # Sum over May: 50 + 50 + 21 = 121 units → 10 free → 111 paid → 12
  # packages of 10.
  {
    "tr-package-1" => [Time.utc(2025, 5, 10, 9, 0, 0), 50],
    "tr-package-2" => [Time.utc(2025, 5, 11, 9, 0, 0), 50],
    "tr-package-3" => [Time.utc(2025, 5, 14, 9, 0, 0), 21]
  }.each do |transaction_id, (timestamp, value)|
    FactoryBot.create(
      :event,
      organization_id: organization.id,
      transaction_id: transaction_id,
      code: "packaged_calls",
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
    current_aggregation: 121,
    max_aggregation: 121
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
      plan_code: "package-plan",
      external_id: SUBSCRIPTION_EXTERNAL_ID,
      name: "Package Sub",
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
puts "invoice_package capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
