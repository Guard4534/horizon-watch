#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

project=horizon-watch-smoke
export HORIZON_WATCH_PORT="${HORIZON_WATCH_PORT:-18080}"
export HORIZON_WATCH_IMAGE="${HORIZON_WATCH_IMAGE:-horizon-watch:smoke}"

compose=(docker compose --env-file /dev/null -p "$project" -f compose.prod.yaml)
up=(up -d --wait --wait-timeout 300)

if [[ "${HORIZON_WATCH_SKIP_BUILD:-0}" != 1 ]]; then
    compose+=(-f compose.build.yaml)
    up+=(--build)
fi

cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
    echo "smoke: $*" >&2
    "${compose[@]}" logs --tail=80 >&2 || true
    exit 1
}

"${compose[@]}" "${up[@]}" || fail "stack did not become healthy"

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

headers="$(curl -fsS -D - -o /dev/null "$base/build/manifest.json")" || fail "/build/manifest.json is not served"
grep -qi '^x-frame-options:' <<<"$headers" || fail "static assets are served without X-Frame-Options"
grep -qi '^x-content-type-options:' <<<"$headers" || fail "static assets are served without X-Content-Type-Options"
grep -qi '^content-security-policy:' <<<"$headers" || fail "static assets are served without a Content-Security-Policy"

page_headers="$(curl -fsS -D - -o /dev/null "$base/setup")" || fail "/setup is not served"
grep -qi '^referrer-policy: strict-origin-when-cross-origin' <<<"$page_headers" || fail "pages are served without a Referrer-Policy"
grep -qi '^permissions-policy:' <<<"$page_headers" || fail "pages are served without a Permissions-Policy"

artisan_probe="$("${compose[@]}" run --rm -T web artisan tinker --execute='echo "uid:".trim(shell_exec("id -u")).":".(config("app.key") ? "key" : "nokey");' | tr -d '\r')"
[[ "$artisan_probe" == *"uid:33:key"* ]] || fail "the artisan role did not run as www-data with an app key: $artisan_probe"

echo "smoke: ok"
