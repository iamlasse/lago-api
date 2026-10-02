# frozen_string_literal: true

# Live REST route dump for the coverage ledger. Runs INSIDE the Rails app
# (it boots the real route table — static parsing is forbidden by the plan).
#
# Usage (from the getlago/lago-api checkout, e.g. via its docker image):
#
#   docker compose run --rm api bash -c \
#     'bundle exec rails runner /path/to/gen_routes.rb' > rest_dump.json
#
# or with the repo mounted:
#
#   bin/rails runner scripts/gen_routes.rb
#
# Output: a JSON array on STDOUT, one object per route:
#   { "verb": "GET", "path": "/api/v1/customers/:external_id",
#     "name": "api_v1_customer", "handler": "api/v1/customers#show" }
#
# Post-process into tests/inventory/rest.json with:
#   php artisan inventory:import-rest < rest_dump.json   (planned; until then
#   merge by hand — the row id format is "rest:<VERB>:<path>").

routes = Rails.application.routes.routes.map do |route|
  verb = route.verb.to_s.split("|").map { |v| v.upcase == "GET" ? "GET" : v.upcase }.reject { |v| v == "HEAD" }

  {
    "verb" => verb.join("|"),
    "path" => route.path.spec.to_s.chomp("(.:format)"),
    "name" => route.name.to_s,
    "handler" => "#{route.defaults[:controller]}##{route.defaults[:action]}",
  }
end

puts JSON.pretty_generate(routes)
