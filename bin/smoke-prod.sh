#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

mode="${1:-fresh}"
project=horizon-watch-smoke
export HORIZON_WATCH_PORT="${HORIZON_WATCH_PORT:-18080}"
export HORIZON_WATCH_IMAGE="${HORIZON_WATCH_IMAGE:-horizon-watch:smoke}"
export DB_PASSWORD="${DB_PASSWORD:-smoke-database-password}"

stub=(-f docker/smoke/horizon.yaml)
compose=(docker compose --env-file /dev/null -p "$project" --project-directory "$PWD" -f compose.prod.yaml "${stub[@]}")
up=(up -d --wait --wait-timeout 300 --remove-orphans)

if [[ "${HORIZON_WATCH_SKIP_BUILD:-0}" != 1 ]]; then
    compose+=(-f compose.build.yaml)
    up+=(--build)
fi

active=("${compose[@]}")
workdir="$(mktemp -d)"

cleanup() {
    "${active[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$workdir"
}
trap cleanup EXIT

fail() {
    echo "smoke: $*" >&2
    "${active[@]}" logs --tail=80 >&2 || true
    exit 1
}

base="http://127.0.0.1:${HORIZON_WATCH_PORT}"

seed() {
    "${active[@]}" run --rm -T web artisan tinker --execute='
        $user = app(App\Actions\Setup\CompleteSetup::class)->handle(App\Data\Auth\SetupData::from([
            "name" => "Smoke",
            "email" => "smoke@example.com",
            "password" => "smoke-password-1",
            "organization" => "Smoke",
        ]));
        $application = $user->currentTeam->applications()->create(["name" => "Shop", "host" => "shop.example.com"]);
        $application->environments()->create([
            "name" => "production",
            "color" => "prod",
            "horizon_url" => "http://horizon:8080/horizon",
            "basic_auth_user" => "smoke",
            "basic_auth_password" => "smoke-basic-auth",
            "poll_interval_seconds" => 15,
        ]);
        echo "seeded";
    ' | tr -d '\r'
}

readings() {
    "${active[@]}" exec -T postgres psql -U horizon_watch -d horizon_watch -tAc 'select count(*) from environment_snapshots where error is null' | tr -d '[:space:]'
}

wait_for_reading() {
    local before="$1"
    local deadline=$((SECONDS + 120))

    until [[ "$(readings)" -gt "$before" ]]; do
        ((SECONDS < deadline)) || fail "no successful reading of the Horizon stub was stored within 2 minutes: the scheduler or the workers do not poll, or the polls fail"
        sleep 3
    done
}

check_fresh_install() {
    curl -fsS "$base/up" >/dev/null || fail "/up is not healthy"

    [[ "$(curl -s -o /dev/null -w '%{redirect_url}' "$base/")" == */login ]] || fail "/ should redirect to /login"
    [[ "$(curl -s -o /dev/null -w '%{redirect_url}' "$base/login")" == */setup ]] || fail "/login should redirect to /setup on a fresh install"
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/setup")" == 200 ]] || fail "/setup should answer 200"

    local running
    running="$("${active[@]}" ps --status running --services | sort | tr '\n' ' ')"
    [[ "$running" == "horizon notifier postgres scheduler web worker " ]] || fail "unexpected running services: $running"

    local workers
    workers="$("${active[@]}" ps --status running -q worker | wc -l)"
    [[ "$workers" -eq "${HORIZON_WATCH_WORKERS:-2}" ]] || fail "expected ${HORIZON_WATCH_WORKERS:-2} running workers, found $workers"

    local notifiers
    notifiers="$("${active[@]}" ps --status running -q notifier | wc -l)"
    [[ "$notifiers" -eq 1 ]] || fail "expected 1 running notifier, found $notifiers"

    "${active[@]}" exec -T worker pgrep -f 'artisan queue:work --queue=polls,default ' >/dev/null || fail "the worker does not serve the polls queue"
    "${active[@]}" exec -T notifier pgrep -f 'artisan queue:work --queue=notifications,default ' >/dev/null || fail "the notifier does not serve the notifications queue"

    "${active[@]}" exec -T web test -s /data/app-key || fail "the app key was not persisted"

    local headers
    headers="$(curl -fsS -D - -o /dev/null "$base/build/manifest.json")" || fail "/build/manifest.json is not served"
    grep -qi '^x-frame-options:' <<<"$headers" || fail "static assets are served without X-Frame-Options"
    grep -qi '^x-content-type-options:' <<<"$headers" || fail "static assets are served without X-Content-Type-Options"
    grep -qi '^content-security-policy:' <<<"$headers" || fail "static assets are served without a Content-Security-Policy"

    local page_headers
    page_headers="$(curl -fsS -D - -o /dev/null "$base/setup")" || fail "/setup is not served"
    grep -qi '^referrer-policy: strict-origin-when-cross-origin' <<<"$page_headers" || fail "pages are served without a Referrer-Policy"
    grep -qi '^permissions-policy:' <<<"$page_headers" || fail "pages are served without a Permissions-Policy"

    local artisan_probe
    artisan_probe="$("${active[@]}" run --rm -T web artisan tinker --execute='echo "uid:".trim(shell_exec("id -u")).":".(config("app.key") ? "key" : "nokey");' | tr -d '\r')"
    [[ "$artisan_probe" == *"uid:33:key"* ]] || fail "the artisan role did not run as www-data with an app key: $artisan_probe"

    local web_log
    web_log="$("${active[@]}" logs web)"
    grep -q '"GET /setup ' <<<"$web_log" || fail "requests are not written to the access log"
    grep -q '"GET /up ' <<<"$web_log" && fail "the health checks of /up fill the access log"

    local before
    before="$(readings)"
    [[ "$(seed)" == *seeded* ]] || fail "could not create an account and an environment"
    wait_for_reading "$before"
}

check_upgrade() {
    local previous="${HORIZON_WATCH_PREVIOUS:-}"

    if [[ -z "$previous" ]]; then
        previous="$(git tag --list 'v[0-9]*' --no-contains HEAD --sort=-v:refname | grep -v -- - | head -n 1 || true)"
    fi

    [[ -n "$previous" ]] || fail "no earlier release to upgrade from: fetch the tags, or set HORIZON_WATCH_PREVIOUS"

    git show "$previous:compose.prod.yaml" >"$workdir/compose.prod.yaml" || fail "$previous has no compose.prod.yaml"

    active=(env "HORIZON_WATCH_IMAGE=${HORIZON_WATCH_PREVIOUS_IMAGE:-ghcr.io/guard4534/horizon-watch:${previous#v}}" docker compose --env-file /dev/null -p "$project" --project-directory "$PWD" -f "$workdir/compose.prod.yaml" "${stub[@]}")

    "${active[@]}" up -d --wait --wait-timeout 300 || fail "$previous did not become healthy"
    [[ "$(seed)" == *seeded* ]] || fail "could not create an account and an environment on $previous"

    local key
    key="$("${active[@]}" exec -T web cat /data/app-key)"

    active=("${compose[@]}")
    "${active[@]}" "${up[@]}" || fail "the stack did not become healthy after upgrading from $previous"

    local before
    before="$(readings)"

    "${active[@]}" run --rm -T web artisan migrate:status --pending=1 >/dev/null || fail "migrations are still pending after upgrading from $previous"

    [[ "$("${active[@]}" exec -T web cat /data/app-key)" == "$key" ]] || fail "the app key changed while upgrading from $previous"

    local probe
    probe="$("${active[@]}" run --rm -T web artisan tinker --execute='
        $environment = App\Models\Environment::sole();
        echo "users:".App\Models\User::count()
            .":admin:".(int) App\Models\User::sole()->is_instance_admin
            .":password:".(int) ($environment->basic_auth_password === "smoke-basic-auth")
            .":production:".(int) $environment->is_production;
    ' | tr -d '\r')"
    [[ "$probe" == *"users:1:admin:1:password:1:production:1"* ]] || fail "the data did not survive the upgrade from $previous: $probe"

    [[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/login")" == 200 ]] || fail "/login should answer 200 once an account exists"

    wait_for_reading "$before"
}

case "$mode" in
    fresh)
        "${active[@]}" "${up[@]}" || fail "stack did not become healthy"
        check_fresh_install
        ;;
    upgrade)
        check_upgrade
        ;;
    *)
        echo "usage: $0 [fresh|upgrade]" >&2
        exit 2
        ;;
esac

echo "smoke: $mode ok"
