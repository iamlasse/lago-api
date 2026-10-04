# frozen_string_literal: true

# Contract scenario: wallets_lifecycle — the REST wallets surface:
# wallet create (paid + granted credits), index filtered by customer,
# show, update, wallet_transactions create (granted → settled, paid →
# pending — the settlement job never runs under the :test adapter),
# a pool-wide VOID (voided_credits), show of a minted transaction,
# the wallet-scoped transactions index (status filters), terminate,
# and show-after-terminate.
#
# Addressing: everything a request MINTS (the created wallet, the
# transactions) is referenced via TOKENS in the manifest — capture! rewrites
# the tokenizable values (see `tokens:`) and the replay test substitutes the
# ids ITS own requests minted (see WalletsLifecycleTest). The SEEDED wallet
# carries a deterministic id so most paths need no substitution.
#
# List determinism: the transactions index sorts created_at DESC with a
# minted-id tie-break, so the three POST /wallet_transactions requests each
# run one frozen second apart (the manifest records the per-request instant
# as `at`; the replay advances its clock the same way — see ContractCase).
#
# Run via scripts/contract/capture.sh wallets_lifecycle — see auth_org.rb
# for the general mechanics.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

ORG_ID = "1a4a0d6e-0000-4000-8000-000000000101"
SEED_WALLET_ID = "1a4a0d6e-0000-4000-8000-000000000102"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000001bb"
CUSTOMER_EXTERNAL_ID = "wallet-customer-1"
CAPTURED_AT = Time.utc(2025, 6, 6, 12, 0, 0)
SEEDED_AT = CAPTURED_AT - 3600
EXPIRATION_AT = Time.utc(2025, 7, 6, 0, 0, 0)

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

# tokens: {real_value => MANIFEST_TOKEN} — the REAL value is sent to Rails
# (the capture must exercise the actual lookup); the manifest entry carries
# the token so the replay can substitute its own minted value.
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
    name: "Contract Wallets Org",
    slug: "contract-wallets-org",
    webhook_url: nil,
    api_keys: []
  )

  api_key = FactoryBot.create(:api_key, organization: organization, name: "Wallets Capture Key")
  api_key.update_columns(value: API_KEY_VALUE)

  customer = FactoryBot.create(
    :customer,
    organization: organization,
    external_id: CUSTOMER_EXTERNAL_ID,
    name: "Wallet Customer Co.",
    firstname: "Wal",
    lastname: "Let",
    email: "wallet-customer@example.invalid",
    currency: "EUR",
    country: "FR",
    city: "Paris",
    address_line1: "3 Rue du Portefeuille",
    zipcode: "75006",
    state: "IDF"
  )

  # The seeded wallet carries a deterministic id: every request that
  # ADDRESSES a wallet (show / update / transactions index / terminate) uses
  # it, so no substitution is needed for those paths.
  FactoryBot.create(
    :wallet,
    id: SEED_WALLET_ID,
    organization: organization,
    customer: customer,
    name: "Seeded Wallet",
    code: "seeded-wallet",
    status: "active",
    currency: "EUR",
    rate_amount: "1.0",
    credits_balance: 0,
    balance_cents: 0,
    consumed_credits: 0,
    expiration_at: EXPIRATION_AT
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

auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}
  json = auth.merge("Content-Type" => "application/json")

  # 1. create a wallet with both credit kinds (paid top-up + grant) and a
  #    termination date. Minted wallet id — later requests never address it.
  capture!(:post, "/api/v1/wallets",
    headers: json,
    body: {wallet: {
      external_customer_id: CUSTOMER_EXTERNAL_ID,
      name: "Captured Wallet",
      code: "captured-wallet",
      currency: "EUR",
      rate_amount: "1.0",
      paid_credits: "10.0",
      granted_credits: "5.0",
      expiration_at: EXPIRATION_AT.iso8601,
      metadata: {"channel" => "contract"}
    }})

  # 2. index filtered by the customer — seeded wallet (an hour older) and
  #    the request-minted one, stable created_at ordering.
  capture!(:get, "/api/v1/wallets?external_customer_id=#{CUSTOMER_EXTERNAL_ID}", headers: auth)

  # 3. show the seeded wallet.
  capture!(:get, "/api/v1/wallets/#{SEED_WALLET_ID}", headers: auth)

  # 4. update it (rename + new expiration).
  capture!(:put, "/api/v1/wallets/#{SEED_WALLET_ID}",
    headers: json,
    body: {wallet: {name: "Seeded Wallet Renamed", expiration_at: Time.utc(2025, 8, 6, 0, 0, 0).iso8601}})

  # 5-7. transactions on the seeded wallet, one frozen second apart so the
  #      created_at-desc index is runtime-stable:
  #      #5 granted credits → settled immediately;
  #      #6 paid credits → pending (the settlement job is recorded, never
  #         run, under the :test adapter — pending IS the captured contract);
  #      #7 a pool-wide VOID of 2.5 credits (no voided_transaction_id: the
  #         pool shrinks; the void transaction settles immediately).
  capture!(:post, "/api/v1/wallet_transactions",
    headers: json, at: CAPTURED_AT + 1,
    body: {wallet_transaction: {
      wallet_id: SEED_WALLET_ID,
      granted_credits: "5.0",
      name: "grant five"
    }})

  capture!(:post, "/api/v1/wallet_transactions",
    headers: json, at: CAPTURED_AT + 2,
    body: {wallet_transaction: {
      wallet_id: SEED_WALLET_ID,
      paid_credits: "10.0",
      name: "paid ten"
    }})

  capture!(:post, "/api/v1/wallet_transactions",
    headers: json, at: CAPTURED_AT + 3,
    body: {wallet_transaction: {
      wallet_id: SEED_WALLET_ID,
      voided_credits: "2.5",
      name: "pool void"
    }})

  # 8. the wallet-scoped transactions index — three rows, distinct
  #    created_at, newest first (void, paid-pending, granted).
  capture!(:get, "/api/v1/wallets/#{SEED_WALLET_ID}/wallet_transactions",
    headers: auth, at: CAPTURED_AT + 4)

  # 9. status filter — only the settled rows (granted + void).
  capture!(:get, "/api/v1/wallets/#{SEED_WALLET_ID}/wallet_transactions?status=settled",
    headers: auth, at: CAPTURED_AT + 4)

  # 10. transaction_status filter — only the purchased (paid, pending) row.
  capture!(:get, "/api/v1/wallets/#{SEED_WALLET_ID}/wallet_transactions?transaction_status=purchased",
    headers: auth, at: CAPTURED_AT + 4)

  # 11. show a MINTED transaction (id minted by request #5 — tokenized in
  #     the manifest; the replay substitutes its own).
  granted_tx_id = JSON.parse(File.read(File.join(GOLDENS, "5.json"))).dig("wallet_transactions", 0, "lago_id")
  capture!(:get, "/api/v1/wallet_transactions/#{granted_tx_id}",
    headers: auth, at: CAPTURED_AT + 5,
    tokens: {granted_tx_id => "GRANTED_TRANSACTION_ID"})

  # 12. terminate the seeded wallet.
  capture!(:delete, "/api/v1/wallets/#{SEED_WALLET_ID}", headers: auth)

  # 13. show after terminate — status terminated.
  capture!(:get, "/api/v1/wallets/#{SEED_WALLET_ID}", headers: auth)

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "wallets_lifecycle capture complete: #{MANIFEST[:requests].length} requests"
