# frozen_string_literal: true

# Contract scenario: auth_org — the M1 auth surface, including the
# cross-language JWT claim (a Rails-minted token must authenticate in
# Laravel, and vice versa).
#
# Run via scripts/contract/capture.sh auth_org (which docker-copies this file
# into the capture container and runs it with `bin/rails runner`). The script:
#   * seeds a scratch DB with Rails' own factories at deterministic ids
#     (no randomness in anything the responses echo back),
#   * freezes the clock (travel_to) — the manifest records the instant,
#   * dumps the SEED STATE to <goldens>/fixture.sql BEFORE issuing the
#     requests (the replay must start from pre-request state; state created
#     by the requests themselves is re-created by Laravel's replay),
#   * issues each request in-process via ActionDispatch::Integration::Session
#     (no rspec needed), writing the parsed JSON response to <n>.json
#     (1-based, in manifest order),
#   * writes manifest.json: captured_at + [method, path, headers, body,
#     required_headers] per request.

require "json"

GOLDENS = ENV.fetch("LAGO_GOLDENS_DIR") do
  abort "capture.sh must set LAGO_GOLDENS_DIR"
end

# Deterministic ids so the Laravel replay sees the exact same database.
ORG_ID = "1a4a0d6e-0000-4000-8000-000000000001"
USER_ID = "1a4a0d6e-0000-4000-8000-000000000002"
API_KEY_VALUE = "1a4a0d6e-0000-4000-8000-0000000000aa"
CAPTURED_AT = Time.utc(2025, 6, 1, 12, 0, 0)

Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)

SESSION = ActionDispatch::Integration::Session.new(Rails.application)
MANIFEST = {captured_at: CAPTURED_AT.iso8601, requests: []}

def capture!(verb, path, headers: {}, body: nil, required_headers: {})
  MANIFEST[:requests] << {
    method: verb.to_s.upcase, path: path,
    headers: headers, body: body, required_headers: required_headers
  }

  # Rails 8 integration helpers take kwargs only (params:/headers:) — a
  # String `params` is sent as the raw request body.
  kwargs = {headers: headers}
  kwargs[:params] = body.nil? ? nil : JSON.generate(body)
  SESSION.public_send(verb, path, **kwargs)

  File.write(
    File.join(GOLDENS, "#{MANIFEST[:requests].length}.json"),
    JSON.pretty_generate(JSON.parse(SESSION.response.body))
  )
  warn "captured ##{MANIFEST[:requests].length} #{verb.to_s.upcase} #{path} -> #{SESSION.response.status}"
end

extend ActiveSupport::Testing::TimeHelpers

travel_to(CAPTURED_AT) do
  organization = FactoryBot.create(
    :organization,
    id: ORG_ID,
    name: "Contract Capture Org",
    slug: "contract-capture-org",
    webhook_url: nil,   # skip the factory's Faker webhook endpoint
    api_keys: []        # the factory's build-strategy api_key; added below
  )

  user = FactoryBot.create(
    :user,
    id: USER_ID,
    email: "capture@example.invalid",
    password: "capture-password"
  )

  # NOTE: `roles:` (plural) is the factory's supported API — it maps each
  # symbol to a membership_role trait. `role:` (singular) would hand the
  # string to MembershipRole#role= and raise AssociationTypeMismatch.
  FactoryBot.create(:membership, user: user, organization: organization, roles: [:admin])

  # ApiKey#set_value (before_create) always overwrites the value with
  # SecureRandom.uuid — set the deterministic one afterwards.
  api_key = FactoryBot.create(:api_key, organization: organization, name: "Capture API Key")
  api_key.update_columns(value: API_KEY_VALUE)

  # ---- SEED STATE IS FROZEN HERE ------------------------------------------
  # fixture.sql must hold the database as it was BEFORE the captured requests
  # ran: the replay re-issues the requests against this state and must see
  # the same mutations Rails saw (e.g. the webhook endpoint created by the
  # PUT below). Dumping after the requests would double them on replay.
  # --inserts (not COPY): the replay loads this through PDO (a plain
  # multi-statement exec), and pdo_pgsql cannot consume inline COPY data.
  dump = IO.popen(
    ["pg_dump", "--data-only", "--inserts",
      "--exclude-table=ar_internal_metadata",
      "--exclude-table=schema_migrations",
      "--exclude-schema=partman", # pg_partman extension tables (not in the Laravel schema)
      ENV.fetch("DATABASE_URL")],
    &:read
  )
  abort "pg_dump failed (empty output)" if dump.strip.empty?

  # pg_dump >= 18 emits \restrict/\unrestrict psql meta-commands, which psql
  # understands but a raw PDO exec (ContractCase#loadFixture) does not, plus
  # `SET transaction_timeout` which predates PG17 servers and errors on them.
  # Keep every other backslash line — `\.` (COPY terminator) is gone anyway
  # with --inserts.
  dump = dump.lines
    .reject { |line| line.start_with?("\\restrict", "\\unrestrict") }
    .reject { |line| line.start_with?("SET transaction_timeout") }
    .join

  File.write(File.join(GOLDENS, "fixture.sql"), dump)
  warn "seed state dumped to fixture.sql"

  auth = {"Authorization" => "Bearer #{API_KEY_VALUE}"}

  # 1. REST: organization show (Bearer UUID auth, singleton show route).
  capture!(:get, "/api/v1/organizations",
    headers: auth)

  # 2. REST: organization update (params wrapped in `organization:` — see
  #    spec/requests/api/v1/organizations_controller_spec.rb). Setting
  #    webhook_url makes the update service create a webhook endpoint, so the
  #    replay exercises the same mutation.
  capture!(:put, "/api/v1/organizations",
    headers: auth.merge("Content-Type" => "application/json"),
    body: {organization: {
      legal_name: "Contract Capture Legal",
      email_settings: ["invoice.finalized"],
      document_number_prefix: "ORG-CAP",
      webhook_url: "https://example.invalid/webhooks",
      billing_configuration: {
        invoice_footer: "capture footer",
        invoice_grace_period: 3,
        document_locale: "en"
      }
    }})

  # 3. GraphQL: login (bcrypt password_digest — cross-language compatible).
  capture!(:post, "/graphql",
    headers: {"Content-Type" => "application/json"},
    body: {
      query: "mutation($input: LoginUserInput!) { loginUser(input: $input) { token user { email } } }",
      variables: {input: {email: user.email, password: "capture-password"}}
    })

  # 4. GraphQL: currentUser with the Rails-minted JWT (must authenticate
  #    identically in Laravel — see services/utils/auth_token.rb).
  token = Utils::AuthToken.encode(user_id: USER_ID)
  capture!(:post, "/graphql",
    headers: {"Authorization" => "Bearer #{token}", "Content-Type" => "application/json"},
    body: {query: "{ currentUser { email organizations { id name } } }"})
end

File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(MANIFEST))
puts "auth_org capture complete: #{MANIFEST[:requests].length} requests"
