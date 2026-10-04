#!/usr/bin/env bash
#
# Contract-golden capture pipeline (Rails side).
#
#   scripts/contract/capture.sh <scenario>
#
# For scenario "auth_org" this:
#   1. ensures the capture container (lago-rails-capture, built from the
#      upstream getlago/lago-api Dockerfile with --build-arg BUNDLE_WITH=test)
#      is running against the scratch Postgres DB;
#   2. recreates the scratch DB and loads the Rails db/structure.sql into it;
#   3. docker-copies the scenario script in and runs it via
#      `bin/rails runner` (RAILS_ENV=test). The scenario seeds the DB with
#      Rails' own factories at deterministic ids under a frozen clock, dumps
#      the SEED STATE (pre-request!) to <goldens>/fixture.sql, then issues
#      each request in-process and writes goldens/<n>.json + manifest.json;
#   4. copies the goldens out of the container into this repo.
#
# The goldens land in tests/Contract/goldens/<scenario>/ — that is the path
# tests/Contract/ContractCase.php reads. The Rails DB itself is never
# committed.
#
# One-time image build (name matters — scripts and docs reference it):
#
#   docker build -t lago-rails-capture \
#     --build-arg BUNDLE_WITH=test /tmp/lago-api-exploration
#
# BUNDLE_WITH=test is what pulls in factory_bot_rails/faker/rspec; the
# Dockerfile's hardwired BUNDLE_WITHOUT="development test" loses to
# BUNDLE_WITH (bundler: `with` wins), so the official image carries the test
# group without any Dockerfile changes.
#
# Env vars (all defaulted):
#
#   RAILS_IMAGE      image to run                (lago-rails-capture)
#   RAILS_CONTAINER  capture container name      (lago-rails-capture)
#   PG_CONTAINER     postgres container          (lago-laravel-pg, port 5433)
#   SCRATCH_DB       scratch database name       (lago_rails_golden)
#   RAILS_STRUCTURE  path to Rails structure.sql (/tmp/lago-api-exploration/db/structure.sql)
#   SECRET_KEY_BASE  JWT secret — MUST be the same value as api-laravel/.env
#                    so tokens are cross-validatable (default reads it from .env)
#
# Never touch databases lago, lago_test, lago_test_a-r.

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

RAILS_IMAGE="${RAILS_IMAGE:-lago-rails-capture}"
RAILS_CONTAINER="${RAILS_CONTAINER:-lago-rails-capture}"
PG_CONTAINER="${PG_CONTAINER:-lago-laravel-pg}"
SCRATCH_DB="${SCRATCH_DB:-lago_rails_golden}"
RAILS_STRUCTURE="${RAILS_STRUCTURE:-/tmp/lago-api-exploration/db/structure.sql}"
PG_USER="${PG_USER:-postgres}"
PG_HOST_PORT="${PG_HOST_PORT:-5433}"
REDIS_URL="${REDIS_URL:-redis://host.docker.internal:6379/9}"

# The replay runs under phpunit, whose env pins SECRET_KEY_BASE to a fixed
# test value — Rails must sign with THAT so captured tokens verify in the
# Laravel test env. Falls back to .env for out-of-phpunit replays.
SECRET_KEY_BASE="${SECRET_KEY_BASE:-$(grep -oE 'SECRET_KEY_BASE" value="[^"]+"' "${API_LARAVEL_DIR}/phpunit.xml" 2>/dev/null | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')}"
SECRET_KEY_BASE="${SECRET_KEY_BASE:-$(grep -E '^SECRET_KEY_BASE=' "${API_LARAVEL_DIR}/.env" | head -1 | cut -d= -f2)}"
if [[ -z "${SECRET_KEY_BASE}" ]]; then
  echo "SECRET_KEY_BASE missing (set it in ${API_LARAVEL_DIR}/.env or env)" >&2
  exit 1
fi

RUNNER_ENV=(-e RAILS_ENV=test
  -e "DATABASE_URL=postgres://${PG_USER}:postgres@host.docker.internal:${PG_HOST_PORT}/${SCRATCH_DB}"
  -e "REDIS_URL=${REDIS_URL}"
  -e "SECRET_KEY_BASE=${SECRET_KEY_BASE}"
  -e "LAGO_GOLDENS_DIR=/tmp/goldens/${SCENARIO}")

echo "==> [1/5] capture container"
if ! docker ps --format '{{.Names}}' | grep -qx "${RAILS_CONTAINER}"; then
  docker rm -f "${RAILS_CONTAINER}" >/dev/null 2>&1 || true
  # The image's ENTRYPOINT (scripts/start.sh) runs db:migrate + puma and its
  # rake-task load dies on the development-only annotate_rb gem — override it.
  # The RSA key guards config/initializers/rsa_keys.rb (no config/keys in a
  # fresh clone); the value itself is not contract-relevant.
  docker run -d --name "${RAILS_CONTAINER}" \
    --entrypoint sleep \
    --add-host=host.docker.internal:host-gateway \
    -e "LAGO_RSA_PRIVATE_KEY=$(openssl genrsa 2048 2>/dev/null | base64 | tr -d '\n')" \
    "${RAILS_IMAGE}" infinity >/dev/null
  sleep 1
fi

echo "==> [2/5] scratch database ${SCRATCH_DB}"
docker exec "${PG_CONTAINER}" dropdb -U "${PG_USER}" --force "${SCRATCH_DB}" 2>/dev/null || true
docker exec "${PG_CONTAINER}" createdb -U "${PG_USER}" "${SCRATCH_DB}"
docker exec -i "${PG_CONTAINER}" psql -U "${PG_USER}" -d "${SCRATCH_DB}" \
  -v ON_ERROR_STOP=1 -q < "${RAILS_STRUCTURE}"

echo "==> [3/5] running scenario ${SCENARIO} inside Rails"
docker exec "${RUNNER_ENV[@]}" \
  "${RAILS_CONTAINER}" mkdir -p "/tmp/goldens/${SCENARIO}"
docker cp "${SCENARIO_SCRIPT}" "${RAILS_CONTAINER}:/tmp/scenario.rb"
# The scenario writes fixture.sql (seed state), <n>.json goldens and
# manifest.json into /tmp/goldens/<scenario>.
docker exec "${RUNNER_ENV[@]}" -w /app "${RAILS_CONTAINER}" \
  bin/rails runner /tmp/scenario.rb

echo "==> [4/5] copying goldens out"
mkdir -p "${GOLDENS}"
docker cp "${RAILS_CONTAINER}:/tmp/goldens/${SCENARIO}/." "${GOLDENS}/"

echo "==> [5/5] validating capture"
if [[ ! -f "${GOLDENS}/manifest.json" ]]; then
  echo "manifest.json missing — capture incomplete" >&2
  exit 1
fi

if [[ ! -f "${GOLDENS}/fixture.sql" ]]; then
  echo "fixture.sql missing — capture incomplete" >&2
  exit 1
fi

ROWS=$(grep -c '^INSERT INTO' "${GOLDENS}/fixture.sql" || true)
echo "goldens ready: ${GOLDENS} (${ROWS} fixture rows)"
echo "replay:        PAO_DISABLE=1 DB_DATABASE=lago_laravel_golden vendor/bin/pest tests/Contract"
