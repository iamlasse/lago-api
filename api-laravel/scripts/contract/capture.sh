#!/usr/bin/env bash
#
# Contract-golden capture pipeline (Rails side).
#
#   scripts/contract/capture.sh <scenario>
#
# For scenario "auth_org" this:
#   1. runs scripts/contract/scenarios/auth_org.rb inside the Rails app via
#      `bin/rails runner` against a SCRATCH database (the scenario seeds it
#      with Rails' own factories at deterministic ids and a frozen clock);
#   2. the scenario writes parsed response bodies to
#      tests/Contract/goldens/<scenario>/<n>.json plus manifest.json
#      (method/path/body/required-headers per request + the captured instant);
#   3. pg_dumps the scratch DB --data-only to
#      tests/Contract/goldens/<scenario>/fixture.sql.
#
# The goldens are committed to THIS repo; the Rails DB itself is never.
#
# Requirements: a booted Rails stack (docker compose up api + postgres) and
# psql on PATH (or inside the container). Configure with env vars:
#
#   RAILS_RUN_CMD   how to run a ruby script inside Rails
#                   default: docker compose exec -T api bin/rails runner
#   RAILS_APP_DIR   path of the Rails repo as seen by RAILS_RUN_CMD
#                   default: /rails (the image's WORKDIR)
#   PG_DUMP_CMD     how to run pg_dump against the scratch DB
#                   default: docker compose exec -T postgres pg_dump
#   PG_ARGS         pg_dump connection/extra args, e.g. "-U postgres -h localhost lago_scratch"
#                   default: "-U postgres lago_scratch"
#
# TODO(boot): this script cannot run until the Rails stack is booted with a
# scratch database (LAGO_DATABASE_NAME=lago_scratch). Until then every
# contract test skips (see tests/Contract/ContractCase.php) and the ledger's
# contract column stays "untested".

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "usage: $0 <scenario>" >&2
  exit 2
fi

SCENARIO="$1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_LARAVEL_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
GOLDENS="${API_LARAVEL_DIR}/tests/Contract/goldens/${SCENARIO}"
SCENARIO_SCRIPT="${SCRIPT_DIR}/scenarios/${SCENARIO}.rb"

if [[ ! -f "${SCENARIO_SCRIPT}" ]]; then
  echo "no scenario script at ${SCENARIO_SCRIPT}" >&2
  exit 2
fi

RAILS_RUN_CMD="${RAILS_RUN_CMD:-docker compose exec -T api bin/rails runner}"
RAILS_APP_DIR="${RAILS_APP_DIR:-/rails}"
PG_DUMP_CMD="${PG_DUMP_CMD:-docker compose exec -T postgres pg_dump}"
PG_ARGS="${PG_ARGS:--U postgres lago_scratch}"

mkdir -p "${GOLDENS}"

echo "==> [1/3] running scenario ${SCENARIO} inside Rails (scratch DB)"
# The scenario receives the goldens directory via LAGO_GOLDENS_DIR; it must
# write manifest.json, <n>.json response bodies, and use travel_to for the
# captured clock. It must NOT touch lago / lago_test.
# TODO(boot): uncomment once the Rails stack is up:
#
#   docker compose exec -T \
#     -e LAGO_GOLDENS_DIR="${RAILS_APP_DIR}/../api-laravel/tests/Contract/goldens/${SCENARIO}" \
#     -e LAGO_DATABASE_NAME="lago_scratch_${SCENARIO}" \
#     api bash -c "bin/rails db:prepare && bin/rails runner ${SCENARIO_SCRIPT}"
#
# (With bind-mounted repos the goldens land directly in this repo; otherwise
# `docker compose cp` them out of the container into ${GOLDENS}.)

echo "==> [2/3] dumping scratch DB (data only) to fixture.sql"
# TODO(boot): uncomment once the Rails stack is up:
#
#   ${PG_DUMP_CMD} ${PG_ARGS} \
#     --data-only \
#     --exclude-table=ar_internal_metadata \
#     --exclude-table=schema_migrations \
#     > "${GOLDENS}/fixture.sql"

echo "==> [3/3] validating capture"
if [[ ! -f "${GOLDENS}/manifest.json" ]]; then
  echo "manifest.json missing — capture incomplete (see TODO(boot) above)" >&2
  exit 1
fi

if [[ ! -f "${GOLDENS}/fixture.sql" ]]; then
  echo "fixture.sql missing — capture incomplete (see TODO(boot) above)" >&2
  exit 1
fi

echo "goldens ready: ${GOLDENS}"
echo "replay:        DB_DATABASE=lago_test_c vendor/bin/pest tests/Contract --group contract"
