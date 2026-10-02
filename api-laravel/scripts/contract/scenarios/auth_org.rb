# frozen_string_literal: true

# Contract scenario: auth_org — the M1 auth surface, including the
# cross-language JWT claim (a Rails-minted token must authenticate in
# Laravel, and vice versa).
#
# Structure cribbed from Rails' spec/requests/api/v1 specs (see
# spec/requests/api/v1/organizations_controller_spec.rb and
# spec/services/utils/auth_token_spec.rb in getlago/lago-api): factories +
# in-process HTTP calls via ActionDispatch::IntegrationTest.
#
# Run via scripts/contract/capture.sh auth_org (needs a booted Rails stack —
# TODO(boot) there). The script:
#   * seeds a scratch DB with deterministic ids (no randomness — the fixture
#     must replay byte-identically in Laravel),
#   * freezes the clock (travel_to) — the manifest records the instant,
#   * issues each request in-process, writing the parsed JSON response to
#     <n>.json (1-based, in manifest order),
#   * writes manifest.json: captured_at + [method, path, headers, body,
#     required_headers] per request.

# TODO(boot): uncomment when running inside the Rails app.
#
# ENV["LAGO_GOLDENS_DIR"] or raise "capture.sh must set LAGO_GOLDENS_DIR"
# GOLDENS = ENV["LAGO_GOLDENS_DIR"]
#
# require "rails_helper"
# require "json"
#
# # Deterministic ids so the Laravel replay sees the exact same database.
# FactoryBot.define do
#   factory :captured_organization, class: "Organization" do
#     id { "1a4a0d6e-0000-4000-8000-000000000001" }
#     name { "Contract Capture Org" }
#     webhook_url { "https://example.invalid/webhooks" }
#   end
#
#   factory :captured_user, class: "User" do
#     id { "1a4a0d6e-0000-4000-8000-000000000002" }
#     email { "capture@example.invalid" }
#     password { "capture-password" }
#   end
# end
#
# class AuthOrgScenario < ActionDispatch::IntegrationTest
#   include FactoryBot::Syntax::Methods
#
#   CAPTURED_AT = Time.utc(2025, 6, 1, 12, 0, 0)
#
#   def capture!(verb, path, headers: {}, body: nil, required_headers: {})
#     manifest[:requests] << {
#       method: verb.to_s.upcase, path: path,
#       headers: headers, body: body, required_headers: required_headers,
#     }
#
#     public_send(verb, path, params: body&.to_json, headers: headers)
#
#     File.write(File.join(GOLDENS, "#{manifest[:requests].length}.json"),
#                JSON.pretty_generate(JSON.parse(response.body)))
#   end
#
#   def manifest
#     @manifest ||= { captured_at: CAPTURED_AT.iso8601, requests: [] }
#   end
# end
#
# travel_to(CAPTURED_AT) do
#   Dir.mkdir(GOLDENS) unless Dir.exist?(GOLDENS)
#   scenario = AuthOrgScenario.new("auth_org capture")
#   org = create(:captured_organization)
#   user = create(:captured_user, organization: org)
#   create(:membership, user: user, organization: org, role: "admin")
#   create(:api_key, organization: org) # value is deterministic via sequence override
#
#   # 1. REST: organization show (Bearer UUID auth).
#   scenario.capture!(:get, "/api/v1/organizations",
#     headers: { "Authorization" => "Bearer #{api_key.value}" },
#     required_headers: { "content-type" => "application/json; charset=utf-8" })
#
#   # 2. REST: organization update.
#   scenario.capture!(:put, "/api/v1/organizations",
#     headers: { "Authorization" => "Bearer #{api_key.value}", "Content-Type" => "application/json" },
#     body: { name: "Renamed" })
#
#   # 3. GraphQL: login (bcrypt password_digest — cross-language compatible).
#   scenario.capture!(:post, "/graphql",
#     headers: { "Content-Type" => "application/json" },
#     body: { query: "mutation($input: LoginUserInput!) { loginUser(input: $input) { token user { email } } }",
#             variables: { input: { email: user.email, password: "capture-password" } } })
#
#   # 4. GraphQL: currentUser with the Rails-minted JWT (must authenticate
#   #    identically in Laravel).
#   token = Utils::AuthToken.new.sub = user.id # see services/utils/auth_token.rb
#   scenario.capture!(:post, "/graphql",
#     headers: { "Authorization" => "Bearer #{token}", "Content-Type" => "application/json" },
#     body: { query: "{ currentUser { email organizations { id name } } }" })
#
#   File.write(File.join(GOLDENS, "manifest.json"), JSON.pretty_generate(scenario.manifest))
# end
#
# puts "auth_org capture complete: #{manifest[:requests].length} requests"
