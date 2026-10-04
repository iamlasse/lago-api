# frozen_string_literal: true

# Contract scenario: catalog_crud — the /api/v2 catalog surface:
# product_categories, products, rate_cards (with a standard rate), a
# contract (the new catalog-plan agreement) with an applied rate card and a
# rate phase override, plus the indexes and shows in between.
#
# Everything under /api/v2 belongs to the product catalog, gated by the
# organization's `product_catalog` FEATURE FLAG (a rollout flag, not a
# license gate — see Organization#product_catalog_enabled?), so the seeded
# org carries feature_flags: ["product_catalog"]. The catalog pipeline is
# otherwise unmodified production code.
#
# Run via scripts/contract/capture.sh catalog_crud — see auth_org.rb for
# the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000151"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-000000000201"
CUSTOMER_EXTERNAL_ID = "catalog-customer-1"
PLAN_CODE = "catalog-contract-plan"
CAPTURED_AT = Time.utc(2025, 6, 11, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

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
    name: "Contract Catalog Org",
    slug: "contract-catalog-org",
    webhook_url: nil,
    api_keys: [],
    feature_flags: ["product_catalog"]
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Catalog Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Catalog Customer Co.",
    firstname: "Cat",
    lastname: "Alog",
    email: "catalog-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "9 Rue du Catalogue",
    zipcode: "75011",
    state: "IDF"
  )

  # The CONTRACT plans (contracts belong_to catalog_plans — the new
  # generation of plans, not the legacy subscriptions table).
  FactoryBot.create(
    :catalog_plan,
    organization: organization,
    name: "Catalog Contract Plan",
    code: PLAN_CODE,
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
end

beta = {"X-Lago-Endpoint-Status" => "beta"}
  auth = beta.merge("Authorization" => "Bearer #{API_KEY_VALUE}")
  json = auth.merge("Content-Type" => "application/json")
  required = {"X-Lago-Endpoint-Status" => "beta"}

  # 1. product category.
  capture!(:post, "/api/v2/product_categories",
    headers: json, required_headers: required,
    body: {product_category: {
      name: "Hardware",
      code: "hardware",
      description: "physical devices"
    }})

  # 2. product in that category — fixed type (no billable metric needed).
  capture!(:post, "/api/v2/products",
    headers: json, required_headers: required, at: CAPTURED_AT + 1,
    body: {product: {
      name: "Keyboard",
      code: "keyboard",
      description: "mechanical keyboard",
      invoice_display_name: "Keyboard",
      product_type: "fixed",
      product_category_code: "hardware"
    }})

  # 3. products index.
  capture!(:get, "/api/v2/products", headers: auth, required_headers: required)

  # 4. product show.
  capture!(:get, "/api/v2/products/keyboard", headers: auth, required_headers: required)

  # 5. product update — rename.
  capture!(:put, "/api/v2/products/keyboard",
    headers: json, required_headers: required,
    body: {product: {name: "Keyboard Pro", description: "mechanical keyboard, pro"}})

  # 6. rate card over the product, one STANDARD rate.
  capture!(:post, "/api/v2/rate_cards",
    headers: json, required_headers: required, at: CAPTURED_AT + 2,
    body: {rate_card: {
      product_code: "keyboard",
      name: "Keyboard Card",
      code: "kb-card",
      description: "keyboard rate card",
      currency: "EUR",
      billing_timing: "arrears",
      proration: false,
      display_on_invoice: true,
      rates: [{
        code: "kb-rate",
        effective_from: CAPTURED_AT.utc.iso8601,
        rate_model: "standard",
        min_amount_cents: 0,
        billing_interval_count: 1,
        billing_interval_unit: "month",
        rate_properties: {"amount" => "0.50"}
      }]
    }})

  # 7. rate cards index.
  capture!(:get, "/api/v2/rate_cards", headers: auth, required_headers: required, at: CAPTURED_AT + 3)

  # 8. rate card show.
  capture!(:get, "/api/v2/rate_cards/kb-card", headers: auth, required_headers: required)

  # 9. contract create — the customer agrees to the catalog plan. started_at
  #    is a frozen month IN THE FUTURE so the contract is created PENDING:
  #    contracts are only editable (applied rate cards, phases) while
  #    pending (Contract#editable?) — an active one answers contract_locked.
  capture!(:post, "/api/v2/contracts",
    headers: json, required_headers: required, at: CAPTURED_AT + 4,
    body: {contract: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      external_id: "contract-1",
      name: "Catalog Contract",
      plan_code: PLAN_CODE,
      billing_time: "calendar",
      started_at: (CAPTURED_AT + 2_592_000).iso8601
    }})

  # 10. contract show.
  capture!(:get, "/api/v2/contracts/contract-1", headers: auth, required_headers: required)

  # 11. apply the keyboard rate card to the contract, 100 units.
  capture!(:post, "/api/v2/contracts/contract-1/applied_rate_cards",
    headers: json, required_headers: required, at: CAPTURED_AT + 5,
    body: {applied_rate_card: {rate_card_code: "kb-card", units: 100}})

  # 12. contract applied rate cards index.
  capture!(:get, "/api/v2/contracts/contract-1/applied_rate_cards",
    headers: auth, required_headers: required, at: CAPTURED_AT + 6)

  # 13. rate phase override — a second, cheaper phase over the applied card.
  capture!(:post, "/api/v2/contracts/contract-1/applied_rate_cards/kb-card/rate_phases",
    headers: json, required_headers: required, at: CAPTURED_AT + 7,
    body: {rate_phase: {
      code: "phase-intro",
      position: 1,
      name: "Intro pricing",
      # A phase WITHOUT a cycle count is indefinite — and only the LAST
      # phase may be indefinite (indefinite_phase_must_be_last).
      billing_interval_cycle_count: 3,
      rate_override: {
        rate_model: "standard",
        rate_properties: {"amount" => "0.25"}
      }
    }})

  # 14. applied rate card show — with its phases.
  capture!(:get, "/api/v2/contracts/contract-1/applied_rate_cards/kb-card",
    headers: auth, required_headers: required, at: CAPTURED_AT + 8)

  # 15. applied rate card update — change the units.
  capture!(:put, "/api/v2/contracts/contract-1/applied_rate_cards/kb-card",
    headers: json, required_headers: required, at: CAPTURED_AT + 9,
    body: {applied_rate_card: {units: 150}})

  # 16. contracts index.
  capture!(:get, "/api/v2/contracts", headers: auth, required_headers: required, at: CAPTURED_AT + 10)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "catalog_crud capture complete: #{MANIFEST[:requests].length} requests"
