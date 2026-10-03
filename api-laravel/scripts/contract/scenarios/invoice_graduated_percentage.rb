# frozen_string_literal: true

# Contract scenario: invoice_graduated_percentage — a subscription invoice
# whose ONLY usage fee is a GRADUATED_PERCENTAGE charge, billed in arrears
# over a full calendar month, so the golden carries the fee's
# `amount_details.graduated_percentage_ranges` (per-tier units, flat and
# rate amounts).
#
# PREMIUM NOTE: graduated_percentage is License.premium?-gated in Rails
# (app/models/charge.rb:181 — a non-premium charge create raises
# :graduated_percentage_requires_premium_license). The OSS capture image has
# no license, so the scenario flips the License singleton the same way the
# Rails suite's own :premium specs do (spec/support/license_helper.rb:
# `License.instance_variable_set(:@premium, true)`) BEFORE seeding the
# charge. Everything downstream is the unmodified production code path —
# the gate itself is not part of this port's surface.
#
# Same flow as invoice_graduated.rb (read its header for the in-process
# billing rationale — SEEDED_AT seed incl. events + one cached_aggregations
# row, request #1 subscription create, travel_to(BILLING_AT) +
# BillSubscriptionJob.perform_now, request #2 invoice index, EXTRA golden
# 10.json = invoice show with the minted id, replayed against the id the
# replay's own billing minted — see InvoiceGraduatedPercentageTest).
#
# Fixture values from Rails' spec/services/charge_models/
# graduated_percentage_service_spec.rb: ranges (0..10 flat 200 @1%),
# (11..20 flat 300 @2%), (21..∞ flat 400 @3%); one May event of value 15 →
# 15 units, all in the first tier.
#
# Run via scripts/contract/capture.sh invoice_graduated_percentage.

require "json"

# The :premium flip — see the header note (same mechanism as
# spec/support/license_helper.rb#lago_premium!).
License.instance_variable_set(:@premium, true)

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000091"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000092"
CHARGE_ID = "1a4a0d6e-0000-4000-8000-000000000093"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-00000000009e"
CUSTOMER_EXTERNAL_ID = "gradpct-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "gradpct-sub-1"
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
    name: "Contract GradPct Org",
    slug: "contract-gradpct-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "GradPct Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "GradPct Customer Co.",
    email: "gradpct@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "15 Rue des Graduations",
    zipcode: "75015",
    state: "IDF",
    timezone: nil
  )

  metric = FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Tiered Pct API Calls",
    code: "tiered_pct_calls",
    description: "seeded sum metric for the graduated_percentage charge",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "GradPct Plan",
    code: "gradpct-plan",
    invoice_display_name: "GradPct Plan Display",
    description: "arrears plan with a graduated_percentage charge",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :graduated_percentage_charge,
    id: CHARGE_ID,
    plan: plan,
    billable_metric: metric,
    organization: organization,
    code: "gradpct-charge",
    invoice_display_name: "Tiered percentage API calls",
    properties: {
      graduated_percentage_ranges: [
        {from_value: 0, to_value: 10, flat_amount: "200", rate: "1"},
        {from_value: 11, to_value: 20, flat_amount: "300", rate: "2"},
        {from_value: 21, to_value: nil, flat_amount: "400", rate: "3"}
      ]
    }
  )

  # Metered input, seeded straight into `events` (see invoice_graduated.rb).
  # One event of value 15 → aggregation 15, all units in the first tier.
  FactoryBot.create(
    :event,
    organization_id: organization.id,
    transaction_id: "tr-gradpct-1",
    code: "tiered_pct_calls",
    timestamp: Time.utc(2025, 5, 14, 9, 0, 0),
    external_customer_id: CUSTOMER_EXTERNAL_ID,
    external_subscription_id: SUBSCRIPTION_EXTERNAL_ID,
    properties: {"calls" => 15}
  )

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
    current_aggregation: 15,
    max_aggregation: 15
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
      plan_code: "gradpct-plan",
      external_id: SUBSCRIPTION_EXTERNAL_ID,
      name: "GradPct Sub",
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
puts "invoice_graduated_percentage capture complete: #{MANIFEST[:requests].length} requests + 1 extra golden"
