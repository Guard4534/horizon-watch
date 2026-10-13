# Horizon Watch

A self-hosted control room for several Laravel Horizon installations. Horizon Watch polls the
Horizon HTTP API of every environment you register, stores the readings, evaluates your
thresholds, opens and resolves alerts, and notifies you by email and by signed webhook. One
wall shows every application and environment at a glance; one page per environment shows the
queues, the throughput and the wait times behind it.

It is not a replacement for Horizon: you keep using each application's own dashboard to
retry or delete jobs. Horizon Watch only ever reads. It never writes to Horizon, and it never
talks to your Redis — every number it shows comes from Horizon's own JSON API over HTTP.

![The wall](.github/screenshots/wall.png)

![An environment](.github/screenshots/environment.png)

![Alert rules](.github/screenshots/alert-rules.png)

## Requirements

- Docker with Compose v2 on the host that runs the panel.
- A PostgreSQL 18 database. `compose.prod.yaml` starts one for you; point the panel at your
  own if you prefer.
- An SMTP server, if you want invitations and alert emails to arrive. There is no fallback
  that writes emails to a log file.
- Network access from the panel to the Horizon dashboard of every monitored application.

The panel itself needs no outbound internet access: there are no CDNs, no remote fonts and no
external lookups.

## Install

Everything ships in one image, `ghcr.io/guard4534/horizon-watch`. You only need
`compose.prod.yaml`:

```bash
curl -fsSLO https://raw.githubusercontent.com/Guard4534/horizon-watch/main/compose.prod.yaml
docker compose -f compose.prod.yaml up -d
```

`compose.prod.yaml` is also attached to every release, if you would rather take it from
there.

That starts five services: `web` (nginx and php-fpm), `scheduler`, two `worker`s that read
Horizon, a `notifier` that sends emails and webhooks, and `postgres`. Settings go in a `.env`
file next to `compose.prod.yaml`, or in your shell environment; see
[Configuration](#configuration). At the very least, change `DB_PASSWORD` and set the mail
server.

Serve the panel over HTTPS in production, behind a reverse proxy that terminates TLS. Set
`APP_URL` to the `https://` address, have the proxy pass the original `Host` header through,
and leave `TRUSTED_PROXIES` at its default if the proxy is on the same private network
(see [Configuration](#configuration)). Besides protecting the session cookie, HTTPS keeps the
one-time webhook secret out of the browser history: the page that shows it asks the browser
to encrypt its history entry, which browsers only do in a secure context.

## First run

Open the panel — `http://localhost:8080` by default. The first visit lands on `/setup` and
asks you to create the administrator and the first organization; from then on `/setup` is
closed and everyone else joins by invitation.

The account created by `/setup` is the instance admin. By default only instance admins can
create organizations, so an invited viewer cannot open an organization of their own; set
`HORIZON_WATCH_ORG_CREATION=everyone` to let every account create them. Grant or revoke the
role from the command line (see [Running commands](#running-commands)); the last instance
admin cannot be revoked. An instance admin can still delete their own account, which may leave
the panel with no instance admin; grant the role again from the command line.

Then, in order:

1. **Applications** → create an application. It is a name, nothing more.
2. Add an **environment** to it: a label (`production`, `staging`, …), the URL of that
   installation's Horizon dashboard (for example `https://shop.example.com/horizon`), the
   basic-auth user and password if the dashboard is behind one, and how often to poll it
   (15 to 300 seconds, in steps of 15: the scheduler queues readings every 15 seconds).
   **Test connection** reads the dashboard once and tells you what it found, or why it
   failed, before you save. Give basic-auth credentials an `https://` address: over
   `http://` the password travels in clear on every poll, and the form warns you. Tick **Production environment** on every production installation:
   members who see everything except production never see those environments. The box is
   ticked for you when the label is `production`, `prod`, `prd` or `live`, alone or followed
   by a suffix such as `production-eu`.
3. **Alert rules** → review the thresholds. Every organization starts with sensible defaults
   and you can override any rule for a single environment.
4. **Alert rules → Notifications** → add the email recipients, and the webhook URL if you
   want one.
5. **Members** → invite the rest of the team and pick which environments each person sees.

Readings start within a minute of saving an environment; the wall fills itself as they
arrive.

## What the monitored application needs

Horizon Watch reads `<horizon dashboard URL>/api/stats`, `/api/masters`, `/api/workload`,
`/api/jobs/failed`, `/api/jobs/pending` and the per-queue metrics. So the monitored
application needs:

- **Horizon installed and reachable over HTTP.** The default path is `/horizon`; if you moved
  it with `HORIZON_PATH`, give Horizon Watch the moved URL. Either the dashboard URL or the
  same URL ending in `/api` is accepted. An address copied from a Horizon screen, such as
  `/horizon/dashboard`, is not: **Test connection** suggests the dashboard URL instead.
- **A way in.** Horizon's `viewHorizon` gate guards those routes outside the `local`
  environment. The simplest arrangement that does not open the dashboard to the world is to
  put HTTP basic auth in front of it at the web-server level and give Horizon Watch the
  credentials; they are stored encrypted. If you gate it in the application instead, make
  sure the gate lets a request without a session through, or Horizon Watch will only ever see
  the login redirect.

Every read is a plain `GET`; nothing Horizon Watch sends changes state. Reads follow no
redirects, time out after a few seconds, stop at 2 MB per response, ask for and accept only
uncompressed answers, ignore any proxy configured in the environment and connect to the
address the name resolved to, so a name that changes between the safety check and the request
cannot redirect the read elsewhere. When `stats`, `masters` or `workload` cannot connect or
answer 502, 503 or 504, they are asked once more after 400 ms, so a restart of the monitored
application does not turn its tile red. A reading keeps at most 500 queues (the longest) and 200
masters, supervisors and jobs per list.

Horizon lists jobs 50 at a time, newest first. Horizon Watch pages through the failed jobs
until it passes the last hour, up to 4 pages: past 200 failures in the hour the count reads
"at least 200", and the failed-jobs rule accepts thresholds up to 199. When the job-runtime
rule is enabled it also reads the oldest pending jobs, where a long-running job sits on a busy
queue. Horizon drops pending jobs after `trim.pending` (60 minutes by default), so a job-runtime
threshold above that only fires if the monitored application keeps them longer. The per-queue
runtimes come from Horizon's metric snapshots, which change every few minutes, so they are
fetched again only every 5 minutes or when a new queue appears.

## Configuration

Everything is an environment variable, set in a `.env` file next to `compose.prod.yaml` or in
the environment of the containers. Everything has a default except the mail server.

| Variable                               | Default                                  | Purpose                                                                                  |
| -------------------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------- |
| `HORIZON_WATCH_IMAGE`                  | `ghcr.io/guard4534/horizon-watch:latest` | Image to run; pin a version tag in production                                            |
| `HORIZON_WATCH_PORT`                   | `8080`                                   | Port published on the host; `127.0.0.1:8080` publishes it on loopback only               |
| `HORIZON_WATCH_WORKERS`                | `2`                                      | Queue workers that read Horizon; see [Workers](#workers)                                 |
| `HORIZON_WATCH_RETENTION_DAYS`         | `30`                                     | Days of readings kept before pruning, at least 7                                         |
| `HORIZON_WATCH_ALERT_RETENTION_DAYS`   | `90`                                     | Days resolved alerts are kept before pruning, at least 1                                 |
| `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` | `false`                                  | Refuse Horizon addresses that resolve to a private network                               |
| `HORIZON_WATCH_ORG_CREATION`           | `admins`                                 | Who may create organizations: `admins` (instance admins only) or `everyone`              |
| `HORIZON_WATCH_DEFAULT_TIMEZONE`       | `UTC`                                    | Time zone new organizations start with                                                   |
| `APP_URL`                              | `http://localhost:8080`                  | Public URL, used in emails, password reset links and webhook payloads                    |
| `APP_LOCALE`                           | `en`                                     | Default language, `en` or `it`                                                           |
| `APP_KEY`                              | generated                                | Leave empty: the first start writes one to the `app-data` volume                         |
| `APP_PREVIOUS_KEYS`                    | _(empty)_                                | Earlier keys, comma-separated, that still decrypt what they encrypted                    |
| `TRUSTED_PROXIES`                      | `private`                                | `private` (loopback and RFC 1918), `*`, or a comma-separated list of addresses and CIDRs |
| `SESSION_SECURE_COOKIE`                | `null`                                   | `null` follows the request scheme; force it with `true` or `false`                       |
| `DB_PASSWORD`                          | `horizon_watch`                          | PostgreSQL password. Change it                                                           |
| `MAIL_MAILER`                          | `smtp`                                   | Mail transport                                                                           |
| `MAIL_HOST`                            | _(empty)_                                | SMTP host. Without it every email fails                                                  |
| `MAIL_PORT`                            | `587`                                    | SMTP port                                                                                |
| `MAIL_USERNAME`                        | _(empty)_                                | SMTP user, when the server asks for one                                                  |
| `MAIL_PASSWORD`                        | _(empty)_                                | SMTP password, when the server asks for one                                              |
| `MAIL_FROM_ADDRESS`                    | `horizon-watch@example.com`              | Sender of invitations and alert emails                                                   |
| `MAIL_TIMEOUT`                         | `10`                                     | Seconds an unresponsive SMTP server may hold a send; keep it below 30                    |

Readings and resolved alerts are pruned once a day. The newest reading of each environment is
never pruned, so an environment paused for longer than the retention still shows its last
numbers.

`TRUSTED_PROXIES` decides which addresses may tell the panel the client's address and scheme
(`X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Port`). The default trusts loopback
and every private address, which covers a reverse proxy in the same Docker network or on the
same private network, and ignores forwarded headers from anywhere else. Because it trusts the
whole private range, any machine on your network that reaches the published port directly can
claim any client address: publish the port only where the proxy needs it
(`HORIZON_WATCH_PORT=127.0.0.1:8080` when the proxy runs on the same host), or list the proxy's
address instead. If your proxy reaches the panel from a public address, list it explicitly;
`*` trusts everyone and should only be used when nothing but the proxy can reach the port.

`X-Forwarded-Host` is never trusted: the panel takes its host from the `Host` header, so the
proxy must pass the original one through. Caddy and Traefik do so by default; nginx needs
`proxy_set_header Host $host;`. Links in emails, password reset links included, are always
built from `APP_URL`.

### Workers

Readings and notifications go through separate queues: the `worker`s read Horizon (queue
`polls`), the `notifier` sends alert emails, webhooks, invitations and password-reset emails
(queue `notifications`). A slow mail server therefore never holds up a reading, and a backlog of
readings never holds up an alert.

A worker reads one environment at a time, usually in well under a second, but in up to 20
seconds when Horizon is slow or does not answer. To keep up even when endpoints are that slow,
set `HORIZON_WATCH_WORKERS` to about `(20 × unresponsive + healthy) / interval`, rounded up,
where `interval` is the poll interval in seconds. For 30 environments polled every 15 seconds,
the default of 2 workers is enough while they all answer, 9 keep up with 5 of them down, and 40
with all of them down: size it for how many endpoints may go dark at the same time. A reading
that waits in the queue longer than its environment's poll interval is dropped, and the worker
log says so (`A poll waited in the queue longer than its interval and was dropped.`): when that
line keeps coming back, raise `HORIZON_WATCH_WORKERS`. A reading that crashes or runs past its
30-second limit is stored as unreachable, so the endpoint-unreachable rule still fires.

Failed jobs are kept for 7 days (`artisan queue:failed`, see
[Running commands](#running-commands)) and then pruned.

## Notifications

Critical alerts are sent as they open, repeat while they stay open (every 15, 30 or 60
minutes, your choice) and are sent again when they resolve. Warnings are collected into a
digest every 15 minutes. Each organization has its own recipients, its own quiet settings and
its own webhook.

Email goes out only through the SMTP server you configure. Until `MAIL_HOST` is set, every
email — invitations and alerts — fails: the panel lists alert emails as not delivered, and
invitations stay in the failed jobs of the queue for 7 days. There is deliberately no fallback
that writes emails to the log, because the log would then hold recipients and links.

### Webhook

An organization can send its alerts to one webhook URL (Alert rules → Notifications). The
panel `POST`s JSON with these headers:

- `Content-Type: application/json`, `User-Agent: HorizonWatch`
- `X-Horizon-Watch-Timestamp`: the Unix time of the send
- `X-Horizon-Watch-Signature`: `sha256=` followed by the hex HMAC-SHA256 of
  `<timestamp>.<raw body>`, keyed with the organization's webhook secret

The secret is shown once, when the first URL is saved or when it is regenerated (serve the
panel over HTTPS, see [Install](#install)). Put any token in the URL path: query strings and
credentials in the URL are refused. The receiver has 5 seconds to answer with a 2xx;
redirects are not followed, the response body is ignored, and a failed delivery is tried
three times (after 10 and 60 seconds) before it is logged as failed.

```json
{
    "event": "alert.opened",
    "delivery_id": "0f6d2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f",
    "alert": {
        "id": "01992f3c-5a4e-7b1d-9c2a-6d3e4f5a6b7c",
        "rule": "horizon.master_inactive",
        "severity": "critical",
        "value": 14,
        "threshold": 5,
        "unit": "min",
        "application": "Shop",
        "environment": "production",
        "opened_at": "2026-09-17T12:02:00Z",
        "resolved_at": null,
        "url": "https://horizon-watch.example.com/acme/environments/shop-production"
    },
    "organization": { "name": "Acme", "slug": "acme" },
    "sent_at": "2026-09-17T12:02:04Z"
}
```

`event` is `alert.opened`, `alert.repeated` or `alert.resolved` (critical alerts),
`alert.digest` (warnings, every 15 minutes, with an `alerts` list instead of `alert`) or
`test` (with `"alert": null`). `url` is `null` once the environment is deleted.
Organization and environment slugs are fixed when they are created: renaming either keeps
its slug, so the links in alerts already sent keep working.

`delivery_id` names one delivery and stays the same when a failed delivery is tried again,
while `sent_at` is the time of each attempt. A receiver that answered too slowly may get the
same delivery twice: deduplicate on `delivery_id`.

Verify the signature before trusting the body, and reject old timestamps:

```php
$body = file_get_contents('php://input');
$timestamp = (int) ($_SERVER['HTTP_X_HORIZON_WATCH_TIMESTAMP'] ?? 0);
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, getenv('HORIZON_WATCH_WEBHOOK_SECRET'));

if (abs(time() - $timestamp) > 300
    || ! hash_equals($expected, $_SERVER['HTTP_X_HORIZON_WATCH_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

This is what `App\Externals\Webhook\Signature::sign()` computes.

## Running commands

`docker compose exec` bypasses the image's entrypoint, so the command would run as root
without an application key. Use `run` and the `artisan` role instead, which loads the key and
drops to the `www-data` user:

```bash
docker compose -f compose.prod.yaml run --rm web artisan about
docker compose -f compose.prod.yaml run --rm web artisan queue:failed
docker compose -f compose.prod.yaml run --rm web artisan schedule:list
```

Make an account an instance admin, or take the role away:

```bash
docker compose -f compose.prod.yaml run --rm web artisan users:instance-admin ada@example.com
docker compose -f compose.prod.yaml run --rm web artisan users:instance-admin ada@example.com --revoke
```

Turn two-factor authentication off for an account that can no longer complete it; the account
then logs in with its password alone and can set it up again:

```bash
docker compose -f compose.prod.yaml run --rm web artisan users:reset-two-factor ada@example.com
```

Encrypt every stored secret again with the current application key, after a key change (see
[Backup and restore](#backup-and-restore)):

```bash
docker compose -f compose.prod.yaml run --rm web artisan secrets:reencrypt
```

## Logs

Every container logs to standard output, so `docker compose logs` is the whole story:

```bash
docker compose -f compose.prod.yaml logs -f web
docker compose -f compose.prod.yaml logs -f worker notifier
```

`web` carries the nginx access log, the php-fpm log and the application log; `worker` and
`notifier` carry the queues. Nothing that could be a credential is ever logged: an unexpected
failure inside the Horizon reader is reported by class, file and line only, because its message
could hold a URL with credentials or a slice of a response body.

## Upgrading

```bash
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
```

Migrations run when the `web` container starts, under an advisory lock, so several replicas
can start at once. Containers get 40 seconds to stop, so each worker finishes the job it holds
and php-fpm the requests it serves. Read [CHANGELOG.md](CHANGELOG.md) before a major version.

## Backup and restore

Two volumes hold everything:

- `postgres-data` — every application, environment, reading, rule and alert.
- `app-data` — the application key. **It decrypts the secrets stored in the database:** the
  Horizon basic-auth passwords, the webhook signing secrets and the two-factor secrets of the
  accounts. Lose it and none of them can be read again; see
  [Troubleshooting](#troubleshooting) for what to set again.

Back up both, together:

```bash
docker run --rm -v horizon-watch_postgres-data:/from -v "$PWD":/to alpine \
    tar czf /to/postgres-data.tgz -C /from .
docker run --rm -v horizon-watch_app-data:/from -v "$PWD":/to alpine \
    tar czf /to/app-data.tgz -C /from .
```

Both volume names are prefixed with the Compose project name, which is the name of the
directory holding `compose.prod.yaml` unless you pass `-p`; `docker volume ls` shows the real
names.

Restore into a stopped stack by untarring each archive back into the matching volume. If you
would rather keep the key yourself, set `APP_KEY` in the environment: when it is set, the
entrypoint uses it and never touches `/data/app-key`.

To change the key, put the new one in `APP_KEY` and the old one in `APP_PREVIOUS_KEYS`
(comma-separated when there are several) and restart. Secrets saved with an earlier key stay
readable and everything saved from then on uses the new one. Then encrypt what is already
stored again with the new key:

```bash
docker compose -f compose.prod.yaml run --rm web artisan secrets:reencrypt
```

It lists every secret no configured key can decrypt and fails while there is one; set those
again (see [Troubleshooting](#troubleshooting)) and run it once more. When it succeeds, remove
the old key from `APP_PREVIOUS_KEYS` and restart.

## Troubleshooting

**Every alert email is listed as not delivered, and invitations never arrive.** `MAIL_HOST`
is empty. Set the SMTP settings and restart the stack; there is no log fallback by design.

**`/setup` keeps appearing.** No user exists yet, so every route redirects there. If you have
already created the administrator and still land on `/setup`, the panel is talking to an
empty database — check `DB_*` and that the `postgres` container is the one holding your
volume. Deleting the last account opens `/setup` again on purpose.

**Nobody can create an organization.** Only instance admins can, unless
`HORIZON_WATCH_ORG_CREATION=everyone`. Grant the role with `artisan users:instance-admin`.

**The panel answers 502 and the `web` log says `upstream sent too big header`.** nginx's
FastCGI buffers are too small for the `Link` header that preloads the page's assets.
`docker/prod/nginx.conf` already raises them; if you replaced that file, raise
`fastcgi_buffer_size` and `fastcgi_buffers` again.

**The `app-data` volume is gone, or `APP_KEY` changed.** Without `APP_KEY` a new key is
generated on the next start. If you still have the old key, put it in `APP_PREVIOUS_KEYS` and
restart: everything reads again, and `artisan secrets:reencrypt` moves it to the new key.
Otherwise every secret the old key encrypted is lost, and
everyone is logged out once:

- Environments with basic auth stop being read and say _The stored password cannot be
  decrypted: enter it again_. Open each one and type its password again.
- Webhook deliveries are listed as not delivered, _signing secret unreadable_. Regenerate the
  signing secret on the alert rules page and give the new one to the receiver. Alert emails
  keep going out.
- Accounts with two-factor authentication cannot get past the code, which says the secret
  can no longer be read. Turn it off for each with
  `artisan users:reset-two-factor` (see [Running commands](#running-commands)); they log in
  with their password and set it up again.

**A Horizon URL is refused when saved.** The panel refuses credentials and query strings in
the URL, non-HTTP schemes, and — when `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` is `true` —
addresses that resolve to a private network, including carrier-grade NAT (`100.64.0.0/10`,
used by Tailscale). Link-local and cloud metadata addresses are always refused.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full setup. In short:

```bash
composer install
./vendor/bin/sail up -d
./vendor/bin/sail exec pgsql createdb -U sail testing
./vendor/bin/sail composer run setup
./vendor/bin/sail composer run dev
```

`./vendor/bin/sail composer ci:check` runs everything CI runs: Pint, Larastan, the front-end
lint and type check, and the Pest suite.

`./vendor/bin/sail php artisan migrate:fresh --seed` fills the database with a demo
organization and a few weeks of readings, and creates an administrator:
`admin@example.com` / `password`. Never seed a database you care about.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
