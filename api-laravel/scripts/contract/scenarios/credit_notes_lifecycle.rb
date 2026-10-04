# frozen_string_literal: true

# Contract scenario: credit_notes_lifecycle — the REST credit notes surface
# against a FINALIZED subscription invoice: create (credit + refund split),
# estimate, index, show, update (refund_status), void, show-after-void, and
# the over-credit validation envelope.
#
# The invoice is minted IN-PROCESS (BillSubscriptionJob.perform_now, the
# production billing entry point) BEFORE the seed-state dump — so it rides
# fixture.sql with its id, and the credit note create can reference it
# WITHOUT any minted-id substitution. The billing instant (BILLING_AT) is
# the invoice's created_at/issuing clock on BOTH sides (the Laravel test
# freezes the same instant around its own BillSubscriptionJob).
#
# The credit note's own lago_id is minted by request #1, so show / update /
# void carry a TOKEN in the manifest (see wallets_lifecycle.rb for the
# token mechanics).
#
# Run via scripts/contract/capture.sh credit_notes_lifecycle — see
# auth_org.rb for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000121"
METRIC_ID = "1a4a0d6e-0000-4000-8000-000000000122"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000001dd"
CUSTOMER_EXTERNAL_ID = "credit-customer-1"
SUBSCRIPTION_EXTERNAL_ID = "credit-sub-1"
CAPTURED_AT = Time.utc(2025, 6, 8, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
SUBSCRIPTION_AT = Time.utc(2025, 5, 1, 0, 0, 0)
BILLING_AT = Time.utc(2025, 6, 1, 0, 0, 0)

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

# PREMIUM NOTE: credit notes are License.premium?-gated in Rails
# (CreditNotes::CreateService — a non-premium license answers 403
# feature_unavailable). The OSS capture image has no license, so the
# scenario flips the License singleton the same way the Rails suite's own
# :premium specs do (see invoice_graduated_percentage.rb) BEFORE any
# request. The credit note pipeline downstream is unmodified production
# code.
License.instance_variable_set(:@premium, true)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {}, at: CAPTURED_AT, tokens: {})
  manifest_path = path.dup
  manifest_body = body.nil? ? nil : JSON.generate(body)
  tokens.each do |real, token|
    manifest_path = manifest_path.gsub(real, token)
    manifest_body = manifest_body.gsub(real, token) unless manifest_body.nil?
  end

  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: manifest_path,
    headers: headers, body: manifest_body.nil? ? nil : JSON.parse(manifest_body),
    required_headers: required_headers, at: at.iso8601
  }

  kwargs = {headers: headers}
  kwargs[:params] = body.nil? ? nil : JSON.generate(body)
  travel_to(at) { SESSION.public_send(verb, path, **kwargs) }

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
    name: "Contract Credit Notes Org",
    slug: "contract-credit-notes-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Credit Notes Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Credit Customer Co.",
    firstname: "Cre",
    lastname: "Dit",
    email: "credit-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "5 Rue des Avoirs",
    zipcode: "75008",
    state: "IDF"
  )

  FactoryBot.create(
    :sum_billable_metric,
    id: METRIC_ID,
    organization: organization,
    name: "Credit API Calls",
    code: "credit_calls",
    description: "seeded sum metric",
    field_name: "calls",
    recurring: false
  )

  plan = FactoryBot.create(
    :plan,
    organization: organization,
    name: "Credit Plan",
    code: "credit-plan",
    invoice_display_name: "Credit Plan Display",
    description: "arrears plan, flat 49 EUR",
    interval: "monthly",
    amount_cents: 4900,
    amount_currency: "EUR",
    pay_in_advance: false
  )

  FactoryBot.create(
    :subscription,
    organization: organization,
    customer: customer,
    plan: plan,
    external_id: SUBSCRIPTION_EXTERNAL_ID,
    name: "Credit Sub",
    status: "active",
    billing_time: "calendar",
    subscription_at: SUBSCRIPTION_AT,
    started_at: SUBSCRIPTION_AT,
    activated_at: SUBSCRIPTION_AT
  )
end

# --- IN-PROCESS BILLING at the June boundary (not an HTTP contract —
#     production bills from the clock). Mints the FINALIZED subscription
#     invoice (flat plan fee 4900 cents, no usage) that the credit notes
#     below consume. It happens BEFORE the fixture dump on purpose: the
#     invoice row (with its minted id) rides fixture.sql, so the manifest
#     bodies can address it deterministically.
subscription = Subscription.find_by(external_id: SUBSCRIPTION_EXTERNAL_ID)
travel_to(BILLING_AT) do
  BillSubscriptionJob.perform_now(
    [subscription], BILLING_AT.to_i, invoicing_reason: :subscription_periodic
  )
end

INVOICE_ID = Invoice.find_by(customer: subscription.customer).id
# Credit notes break down per fee (items[]) — the subscription fee's id was
# minted by the in-process billing and rides fixture.sql, so it is
# deterministic on the replay too.
FEE_ID = Invoice.find_by(customer: subscription.customer).fees.first.id

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
end

auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}
  json = auth.merge("Content-Type" => "application/json")

  # 1. create a credit note: 2000 cents of the 4900 invoice back as credit,
  #    broken down per fee (items[]). credit_amount_cents must equal the
  #    item sum; refunds are impossible on an unpaid invoice (the billed
  #    invoice is payment_status pending) so no refund is requested. Minted
  #    credit note id → tokenized in the manifest.
  capture!(:post, "/api/v1/credit_notes",
    headers: json,
    body: {credit_note: {
      invoice_id: INVOICE_ID,
      credit_amount_cents: 2000,
      reason: "other",
      description: "contract credit note",
      items: [{fee_id: FEE_ID, amount_cents: 2000}]
    }})

  credit_note_id = JSON.parse(File.read(File.join(GOLDENS, "1.json"))).dig("credit_note", "lago_id")

  # 2. estimate — read-only math for a prospective credit (before any of the
  #    remaining actions mutate the invoice), same items[] shape.
  capture!(:post, "/api/v1/credit_notes/estimate",
    headers: json,
    body: {credit_note: {
      invoice_id: INVOICE_ID,
      credit_amount_cents: 1000,
      items: [{fee_id: FEE_ID, amount_cents: 1000}]
    }})

  # 3. credit notes index — one row.
  capture!(:get, "/api/v1/credit_notes", headers: auth)

  # 4. show by the minted id.
  capture!(:get, "/api/v1/credit_notes/#{credit_note_id}",
    headers: auth,
    tokens: {credit_note_id => "CREDIT_NOTE_ID"})

  # 5. update the refund status.
  capture!(:put, "/api/v1/credit_notes/#{credit_note_id}",
    headers: json,
    body: {credit_note: {refund_status: "should_refund"}},
    tokens: {credit_note_id => "CREDIT_NOTE_ID"})

  # 6. void it — credits flow back to the invoice's creditable balance.
  capture!(:put, "/api/v1/credit_notes/#{credit_note_id}/void",
    headers: auth,
    tokens: {credit_note_id => "CREDIT_NOTE_ID"})

  # 7. show after void — status voided.
  capture!(:get, "/api/v1/credit_notes/#{credit_note_id}",
    headers: auth,
    tokens: {credit_note_id => "CREDIT_NOTE_ID"})

  # 8. over-credit — the validation envelope: 10000 exceeds the 4900 invoice
  #    (even with the void having returned the 2000 to the creditable
  #    balance, the item sum exceeds what is left).
  capture!(:post, "/api/v1/credit_notes",
    headers: json,
    body: {credit_note: {
      invoice_id: INVOICE_ID,
      credit_amount_cents: 10_000,
      reason: "other",
      items: [{fee_id: FEE_ID, amount_cents: 10_000}]
    }})

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "credit_notes_lifecycle capture complete: #{MANIFEST[:requests].length} requests"
