#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

project=horizon-watch-smoke
export HORIZON_WATCH_PORT="${HORIZON_WATCH_PORT:-18080}"

# --env-file /dev/null: without it compose reads the repository's development
# .env, which sets APP_KEY. The production entrypoint then correctly treats an
# operator-supplied key as authoritative and never writes /data/app-key, so the
# last assertion below failed on every developer machine for a reason that has
# nothing to do with the image. Every variable compose.prod.yaml interpolates
# has a default, and shell environment still wins over the (empty) env file, so
# the exported HORIZON_WATCH_PORT above is unaffected.
compose=(docker compose --env-file /dev/null -p "$project" -f compose.prod.yaml)

cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
    echo "smoke: $*" >&2
    "${compose[@]}" logs --tail=80 >&2 || true
    exit 1
}

"${compose[@]}" up -d --build --wait --wait-timeout 300 || fail "stack did not become healthy"

base="http://127.0.0.1:${HORIZON_WATCH_PORT}"

curl -fsS "$base/up" >/dev/null || fail "/up is not healthy"

[[ "$(curl -s -o /dev/null -w '%{redirect_url}' "$base/")" == */login ]] || fail "/ should redirect to /login"
[[ "$(curl -s -o /dev/null -w '%{redirect_url}' "$base/login")" == */setup ]] || fail "/login should redirect to /setup on a fresh install"
[[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/setup")" == 200 ]] || fail "/setup should answer 200"

running="$("${compose[@]}" ps --status running --services | sort | tr '\n' ' ')"
[[ "$running" == "postgres scheduler web worker " ]] || fail "unexpected running services: $running"

workers="$("${compose[@]}" ps --status running -q worker | wc -l)"
[[ "$workers" -eq "${HORIZON_WATCH_WORKERS:-2}" ]] || fail "expected ${HORIZON_WATCH_WORKERS:-2} running workers, found $workers"

"${compose[@]}" exec -T web test -s /data/app-key || fail "the app key was not persisted"

echo "smoke: ok"
