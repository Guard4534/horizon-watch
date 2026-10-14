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

- Docker with Compose v2.24 or later on the host that runs the panel.
- A PostgreSQL 18 database. `compose.prod.yaml` starts one for you; to use your own, see
  [Your own database](#your-own-database).
- An SMTP server, if you want invitations and alert emails to arrive. There is no fallback
  that writes emails to a log file.
- Network access from the panel to the Horizon dashboard of every monitored application.

The panel itself needs no outbound internet access: there are no CDNs, no remote fonts and no
external lookups.

## Install

Everything ships in one image, `ghcr.io/guard4534/horizon-watch`. You need `compose.prod.yaml`
and a `.env` file next to it. Take the compose file from the latest release: it runs the image
of that release, so a later `pull` never moves you to another version behind your back.

```bash
mkdir horizon-watch && cd horizon-watch
curl -fsSLO https://github.com/Guard4534/horizon-watch/releases/latest/download/compose.prod.yaml
```

Write the `.env` before the first start. PostgreSQL takes its password from `DB_PASSWORD` only
when it creates its data, and `compose.prod.yaml` refuses to start without one:

```dotenv
DB_PASSWORD=a-long-random-password
APP_URL=https://horizon-watch.example.com
MAIL_HOST=smtp.example.com
MAIL_USERNAME=horizon-watch
MAIL_PASSWORD=the-smtp-password
MAIL_FROM_ADDRESS=horizon-watch@example.com
HORIZON_WATCH_SETUP_TOKEN=another-long-random-string
```

`openssl rand -hex 24` makes a good password or token. The file holds secrets: `chmod 600 .env`.
Every other setting is described in [Configuration](#configuration). Then start the panel:

```bash
docker compose -f compose.prod.yaml up -d
```

That starts five services: `web` (nginx and php-fpm), `scheduler`, two `worker`s that read
Horizon, a `notifier` that sends emails and webhooks, and `postgres`. To change `DB_PASSWORD`
later, see [Changing the database password](#changing-the-database-password).

### Behind a reverse proxy

Serve the panel over HTTPS in production, behind a reverse proxy that terminates TLS:

- Set `APP_URL` to the `https://` address and `SESSION_SECURE_COOKIE=true`.
- Have the proxy pass the original `Host` header through, and set `X-Forwarded-For` and
  `X-Forwarded-Proto`. Caddy and Traefik do so by default.
- Publish the panel's port only where the proxy can reach it. With the proxy on the same host,
  `HORIZON_WATCH_PORT=127.0.0.1:8080`; leave `TRUSTED_PROXIES` at its default when the proxy
  reaches the panel from a private address (see [Configuration](#configuration)).
- Send `Strict-Transport-Security` from the proxy once HTTPS works. The panel does not send it
  itself, because the header covers every port of the host name and only you know whether all of
  them speak HTTPS.

With Caddy, which also obtains the certificate:

```caddyfile
horizon-watch.example.com {
    reverse_proxy 127.0.0.1:8080
    header Strict-Transport-Security "max-age=31536000"
}
```

With nginx:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name horizon-watch.example.com;

    ssl_certificate /etc/ssl/horizon-watch/fullchain.pem;
    ssl_certificate_key /etc/ssl/horizon-watch/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000" always;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Besides protecting the session cookie, HTTPS keeps the one-time webhook secret out of the
browser history: the page that shows it asks the browser to encrypt its history entry, which
browsers only do in a secure context.

## First run

Until the first account exists, whoever opens the panel can create the administrator and so
take the instance. With `HORIZON_WATCH_SETUP_TOKEN` set, `/setup` asks for that token first;
without it, keep the port out of reach of anyone else until you have been through setup, for
example with `HORIZON_WATCH_PORT=127.0.0.1:8080` and an SSH tunnel. The token is only ever
checked by `/setup`, and it must be at least 16 characters long: with a shorter one `/setup`
refuses every attempt until you change it and restart.

Open the panel — your `APP_URL`, or `http://localhost:8080` by default. The first visit lands
on `/setup` and asks you to create the administrator and the first organization; from then on
`/setup` is closed and everyone else joins by invitation.

The account created by `/setup` is the instance admin. By default only instance admins can
create organizations, so an invited viewer cannot open an organization of their own; set
`HORIZON_WATCH_ORG_CREATION=everyone` to let every account create them. Grant or revoke the
role from the command line (see [Running commands](#running-commands)); the last instance
admin cannot be revoked. An instance admin can still delete their own account, which may leave
the panel with no instance admin; grant the role again from the command line. Alert emails
start turned on for this account, and off for everyone who joins later: each member turns them
on in their settings.

Then, in order:

1. **Applications** → **Add application**. A wizard creates the application and its
   environments together, in three steps:
    - **Application**: a name, the primary domain (`shop.example.com`) and the Horizon path,
      `horizon` unless the application moved it with `HORIZON_PATH`.
    - **Environments**: one per installation, with a name (`production`, `staging`, …), a
      color, the URL of its Horizon dashboard, the basic-auth user and password if the dashboard
      is behind one, and how often to poll it (15 to 300 seconds, in steps of 15: the scheduler
      queues readings every 15 seconds). The URL is suggested from the domain and the path,
      `https://shop.example.com/horizon` for `production` and
      `https://staging.shop.example.com/horizon` for `staging`; change it when your hosts are
      named otherwise. Give basic-auth credentials an `https://` address: over `http://` the
      password travels in clear on every poll, and the form warns you. Tick **Production
      environment** on every production installation: members who see everything except
      production never see those environments. The box is ticked for you when the name is
      `production`, `prod`, `prd` or `live`, alone or followed by a suffix such as
      `production-eu`.
    - **Verify** reads every dashboard once and tells you what it found, or why it failed,
      before you save.

    **Add environment** on the application's page adds another one later, with the same
    **Test connection**.

2. **Alert settings** → review the thresholds (see [Alert rules](#alert-rules)). Every
   organization starts with the defaults and you can override any rule for a single
   environment. A threshold rule opens its alert at the first reading that breaks it; give it
   a duration (**for … min**, up to 60) and it opens only once the breach has lasted that long,
   so a queue hovering around its threshold does not open and resolve an alert at every
   reading. A reading back under the threshold starts the wait again; a failed reading does
   not, unless the endpoint stays unreadable for longer than the stale window.
3. **Alert settings → Notifications** → add the email recipients, and the webhook URL if you
   want one. While no member receives alert emails and there is neither an extra address nor
   a webhook, the wall, the alerts page and this card warn that alerts reach nobody. The
   warning looks only at who could be told: it stays quiet when you turn email off on every
   rule, or when the only members with alert emails see none of the environments.
4. **Members** → invite the rest of the team, with a role (see
   [Roles and visibility](#roles-and-visibility)), and pick which environments each person sees.
   While no SMTP server is set, an admin can also create a link for a pending invitation
   (**New link**) and hand it over. Whoever opens that link creates the account for the
   invited address without proving they own the mailbox, so once mail is set up only instance
   admins can still create links. The panel keeps only a hash of each invitation code, so
   **New link** and **Resend** issue a new link each time, and the earlier one stops working.

Readings start within a minute of saving an environment; the wall fills itself as they
arrive.

## Alert rules

Every organization starts with these rules. Change them under **Alert settings**, for the whole
organization or for a single environment:

| Rule                 | Opens when                                                        | Default | Severity | Emailed |
| -------------------- | ----------------------------------------------------------------- | ------- | -------- | ------- |
| Horizon inactive     | no master supervisor has been active for at least                 | 5 min   | critical | yes     |
| Endpoint unreachable | the dashboard has timed out or answered an error for at least     | 2 min   | critical | yes     |
| Horizon paused       | Horizon, or every master supervisor, has been paused for at least | 15 min  | warning  | no      |
| Pending jobs         | more jobs wait across all queues than                             | 2000    | warning  | yes     |
| Max wait             | the oldest waiting job has waited longer than                     | 60 s    | warning  | yes     |
| Job runtime          | a job has been running for longer than                            | 120 s   | warning  | no      |
| Failed jobs / hour   | more jobs failed in the last hour than                            | 20      | warning  | yes     |
| Missing workers      | at least this many queues have waiting jobs and no worker         | 4       | warning  | no      |

Each rule can be turned off, given another threshold or severity, and emailed or not; the five
threshold rules, from pending jobs down, can also wait for the breach to last up to 60 minutes
(see [First run](#first-run)). **Emailed** decides only whether the alert also goes to the
recipients' inboxes: the webhook receives every alert (see [Webhook](#webhook)). Critical alerts
are sent as they open and repeated while they stay open; warnings go into a digest every 15
minutes (see [Notifications](#notifications)).

## Roles and visibility

Each member of an organization has one role:

| Role   | Can                                                                                                                            |
| ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| Viewer | see the wall, the applications, the environments and the alerts                                                                |
| Member | also mute alerts, plan maintenance windows, mark anomalies handled and test connections                                        |
| Admin  | also create and edit applications, environments and alert settings, invite, remove and change members, rename the organization |
| Owner  | also delete the organization and hand it to another member                                                                     |

The Members page shows the same matrix. Admins and the owner always see every environment.
Viewers and members see all of them, everything except the environments marked as production,
or a hand-picked list; they receive alert emails only for what they see. An organization has
one owner, who can transfer ownership to another member from the Members page and becomes an
admin. An account that owns an organization other people belong to cannot be deleted until it
hands the organization over.

Instance admins are a separate, panel-wide role: by default only they can create
organizations, and they can always create an invitation link (see [First run](#first-run)).

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

Settings are environment variables, read from the `.env` file next to `compose.prod.yaml` or
from your shell. Compose hands the containers only the variables `compose.prod.yaml` lists,
which are the ones below: anything else in `.env` never reaches the panel. Everything has a
default except `DB_PASSWORD`, and the mail server.

| Variable                               | Default                                     | Purpose                                                                                  |
| -------------------------------------- | ------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `HORIZON_WATCH_IMAGE`                  | `ghcr.io/guard4534/horizon-watch:<release>` | Image to run; the compose file of a release names that release                           |
| `HORIZON_WATCH_PORT`                   | `8080`                                      | Port published on the host; `127.0.0.1:8080` publishes it on loopback only               |
| `HORIZON_WATCH_SETUP_TOKEN`            | _(empty)_                                   | Token, 16 characters or more, `/setup` asks for first; see [First run](#first-run)       |
| `HORIZON_WATCH_WORKERS`                | `2`                                         | Queue workers that read Horizon; see [Workers](#workers)                                 |
| `HORIZON_WATCH_RETENTION_DAYS`         | `30`                                        | Days of readings kept before pruning, at least 7                                         |
| `HORIZON_WATCH_ALERT_RETENTION_DAYS`   | `90`                                        | Days resolved alerts and finished maintenance windows are kept, at least 1               |
| `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` | `false`                                     | Refuse Horizon addresses that resolve to a private network                               |
| `HORIZON_WATCH_ORG_CREATION`           | `admins`                                    | Who may create organizations: `admins` (instance admins only) or `everyone`              |
| `HORIZON_WATCH_DEFAULT_TIMEZONE`       | `UTC`                                       | Time zone new organizations start with                                                   |
| `HORIZON_WATCH_HEARTBEAT_URL`          | _(empty)_                                   | Dead man's switch called every minute; see [Watching the panel](#watching-the-panel)     |
| `APP_NAME`                             | `Horizon Watch`                             | Name in the browser tab, sidebar, installed app and as email sender; see below           |
| `APP_URL`                              | `http://localhost:8080`                     | Public URL, used in emails, password reset links and webhook payloads                    |
| `APP_LOCALE`                           | `en`                                        | Default language, `en` or `it`, of the web pages, alert emails and webhook summaries     |
| `APP_KEY`                              | generated                                   | Leave empty: the first start writes one to the `app-data` volume                         |
| `APP_PREVIOUS_KEYS`                    | _(empty)_                                   | Earlier keys, comma-separated, that still decrypt what they encrypted                    |
| `TRUSTED_PROXIES`                      | `private`                                   | `private` (loopback and RFC 1918), `*`, or a comma-separated list of addresses and CIDRs |
| `SESSION_SECURE_COOKIE`                | `null`                                      | `null` follows the request scheme; force it with `true` or `false`                       |
| `SESSION_LIFETIME`                     | `120`                                       | Minutes of inactivity before a session ends                                              |
| `LOG_LEVEL`                            | `info`                                      | Lowest level written to the log: `debug`, `info`, `warning`, `error`…                    |
| `DB_PASSWORD`                          | _(required)_                                | PostgreSQL password; set it before the first start                                       |
| `DB_HOST`                              | `postgres`                                  | PostgreSQL host; see [Your own database](#your-own-database)                             |
| `DB_PORT`                              | `5432`                                      | PostgreSQL port                                                                          |
| `DB_DATABASE`                          | `horizon_watch`                             | Database name, also used to create the bundled one                                       |
| `DB_USERNAME`                          | `horizon_watch`                             | Database user, also used to create the bundled one                                       |
| `DB_SSLMODE`                           | `prefer`                                    | libpq `sslmode`: `disable`, `prefer` or `require`                                        |
| `MAIL_HOST`                            | _(empty)_                                   | SMTP host. Without it every email fails                                                  |
| `MAIL_PORT`                            | `587`                                       | SMTP port                                                                                |
| `MAIL_SCHEME`                          | _(empty)_                                   | `smtps` for TLS from the first byte; empty picks it from the port (`smtps` on 465)       |
| `MAIL_USERNAME`                        | _(empty)_                                   | SMTP user, when the server asks for one                                                  |
| `MAIL_PASSWORD`                        | _(empty)_                                   | SMTP password, when the server asks for one                                              |
| `MAIL_FROM_ADDRESS`                    | `horizon-watch@example.com`                 | Sender of invitations and alert emails                                                   |
| `MAIL_FROM_NAME`                       | `APP_NAME`                                  | Sender name of invitations and alert emails                                              |
| `MAIL_TIMEOUT`                         | `10`                                        | Seconds an unresponsive SMTP server may hold a send; keep it below 30                    |

Renaming `APP_NAME` signs everyone out, and the panel shows its scheduler and workers as stale
until they next run, because the session cookie and the cache prefix are derived from it.

Readings, resolved alerts and finished maintenance windows are pruned once a day. The newest
reading of each environment is never pruned, so an environment paused for longer than the
retention still shows its last numbers. The same daily run forgets replaced webhook secrets
whose overlap has ended.

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

### Your own database

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and, for a managed
service, `DB_SSLMODE=require`. The image carries no certificate authority for your database, so
`verify-ca` and `verify-full` cannot be used. The database must exist and the user must own it:
the panel creates its tables when `web` starts. To keep the bundled `postgres` from starting,
put this next to `compose.prod.yaml` as `compose.external-db.yaml`:

```yaml
services:
    web:
        depends_on: !reset {}
    postgres:
        profiles: [bundled-postgres]
```

and add `-f compose.external-db.yaml` after `-f compose.prod.yaml` in every command, or set
`COMPOSE_FILE=compose.prod.yaml:compose.external-db.yaml` in `.env` and drop the `-f` options.

### Changing the database password

The bundled PostgreSQL reads `DB_PASSWORD` only when it creates its data, so changing the value
in `.env` alone locks the panel out: `web` keeps restarting with `database not reachable`.
Change it in the database first, then in `.env`, then restart (with your `DB_USERNAME` and
`DB_DATABASE` if you changed them):

```bash
docker compose -f compose.prod.yaml exec postgres psql -U horizon_watch -d horizon_watch \
    -c "ALTER USER horizon_watch PASSWORD 'the-new-password'"
docker compose -f compose.prod.yaml up -d
```

### Workers

Readings and notifications go through separate queues: the `worker`s read Horizon (queue
`polls`), the `notifier` sends alert emails, webhooks, invitations and password-reset emails
(queue `notifications`). A slow mail server therefore never holds up a reading, and a backlog of
readings never holds up an alert.

Deleting an organization takes effect at once, but its stored readings are deleted afterwards in
the background (queue `default`, which both kinds of worker serve) in runs of about 10 seconds
each. While a run is going, the worker that took it is busy, so a large organization can delay a
reading or a notification by up to that long. The daily `monitoring:prune` finishes whatever a
failed run left behind.

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

### Sizing

The panel itself is light: at rest `web` uses about 100 MB of memory and the `scheduler`, each
`worker` and the `notifier` about 50 MB, with CPU only while they poll or render a page. The
database is what grows. Every reading is a row of about 200 bytes, indexes included, so an
environment polled every 15 seconds adds about 1.2 MB a day: with the default 30 days of
retention, about 35 MB per environment, or about 1 GB for 30 environments. A longer poll
interval shrinks it in proportion. Alerts and notifications add little next to the readings.

## Notifications

Critical alerts are sent as they open, repeat while they stay open (every 15, 30 or 60
minutes, your choice) and are sent again when they resolve. Warnings are collected into a
digest every 15 minutes. Each organization has its own recipients, its own quiet settings and
its own webhook.

A resolution goes only to the addresses, and to the webhook host, that received the alert
while it was open. An alert that resolves before its notice goes out is not announced at all:
the panel lists that notice, and its resolution, as not sent.

Email goes out only through the SMTP server you configure. Until `MAIL_HOST` is set, every
email — invitations and alerts — fails: the panel lists alert emails as not delivered, and
invitations stay in the failed jobs of the queue for 7 days. The Members page and the
notification settings say so, and admins can copy an invitation link instead (instance admins
can do that at any time). There is deliberately no fallback that writes emails to the log,
because the log would then hold recipients and links.

An alert email that does not go out is listed with the reason: _mail server unreachable_
(connection, TLS or timeout; tried three times), _mail server refused the login_ or _refused by
the mail server_ (a permanent 5xx answer; both logged at once, without retrying). The worker
log names the exception class behind each failure, never the address.

### Maintenance windows

Before a deploy or a planned Horizon restart, silence what you are about to touch instead of
pausing its collection. A maintenance window covers one environment, one application or the
whole organization, from a start to an end (at most 31 days later), with an optional reason.
Schedule one under Alert settings → Maintenance windows, or use **Silence for…** on an
environment page to start one at once for 30 minutes to 4 hours.

During a window the readings go on, so the charts and the history stay complete, but every
alert of what it covers is muted until the window ends: one that opens is created muted, one
that was already open is held back from the moment the window starts and marked muted at the
next reading, and nothing is sent for either, their resolutions included. A mute set by hand
that is still running is kept, and an alert unmuted by hand stays unmuted for the rest of that
window. When the window ends, an alert still open follows the usual rules: a critical is sent
then, a warning goes into the next digest. Ending a window early lifts its mute at once, unless
another window still covers the alert. The wall and the environment page mark an environment
in maintenance.

Anyone who may mute an alert may plan a window; a member who sees only some environments may
plan one only for an environment they see. Finished windows are pruned with the resolved alerts.

### Webhook

An organization can send its alerts to one webhook URL (Alert settings → Notifications). The
panel `POST`s JSON with these headers:

- `Content-Type: application/json`, `User-Agent: HorizonWatch/<version>` (for example
  `HorizonWatch/v1.0.0`)
- `X-Horizon-Watch-Timestamp`: the Unix time of the send
- `X-Horizon-Watch-Signature`: `sha256=` followed by the hex HMAC-SHA256 of
  `<timestamp>.<raw body>`, keyed with the organization's webhook secret
- `X-Horizon-Watch-Previous-Signature`: the same, keyed with the secret it replaced, for 24
  hours after the secret is regenerated

The secret is shown once: when the first URL is saved, when the URL moves to another host,
and when it is regenerated (serve the panel over HTTPS, see [Install](#install)). Put any
token in the URL path: query strings and credentials in the URL are refused. The receiver
has 5 seconds to answer with a 2xx; redirects are not followed and the response body is
ignored. A timeout, an unreachable receiver, a 5xx, a 408 or a 429 is tried three times
(after 10 and 60 seconds, or later when a 429 asks so with `Retry-After`, up to 5 minutes)
before it is logged as failed; a redirect or any other 4xx is logged as failed at once, with
its status.

```json
{
    "version": 1,
    "event": "alert.opened",
    "delivery_id": "0f6d2c1e-8a4b-4c3d-9e2f-1a2b3c4d5e6f",
    "alert": {
        "id": "01992f3c-5a4e-7b1d-9c2a-6d3e4f5a6b7c",
        "rule": "horizon.master_inactive",
        "severity": "critical",
        "summary": "Shop · production — Horizon inactive for 14 min",
        "value": 14,
        "threshold": 5,
        "unit": "min",
        "detail": {
            "value_is_lower_bound": false,
            "longest_job": null,
            "longest_job_queue": null,
            "queues_without_workers": []
        },
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

`event` is one of:

| Event            | Sent                                                                  | Body             |
| ---------------- | --------------------------------------------------------------------- | ---------------- |
| `alert.opened`   | when a critical alert opens                                           | `alert`          |
| `alert.repeated` | while a critical alert stays open, at the repeat interval you chose   | `alert`          |
| `alert.resolved` | when a critical alert whose opening reached the webhook clears        | `alert`          |
| `alert.digest`   | every 15 minutes, with the warnings opened or resolved since the last | `alerts`, a list |
| `test`           | when someone sends a test from Alert settings → Notifications         | `"alert": null`  |

`version` is `1`. Fields may be added to version 1, so ignore the keys you do not know; a field
that is removed, renamed or changes meaning raises the version. `url` is `null` once the
environment is deleted. Organization and environment slugs are fixed when they are created:
renaming either keeps its slug, so the links in alerts already sent keep working.

`rule` and `unit` take these values; `value` and `threshold` are numbers in that unit. A rule
opens an alert when `value` goes over `threshold`, or reaches it for the first three rules and
for missing workers, and has stayed there for the duration set on the rule, if any:

| `rule`                    | `unit`    | `value`                                          |
| ------------------------- | --------- | ------------------------------------------------ |
| `horizon.master_inactive` | `min`     | minutes Horizon has been inactive                |
| `endpoint.unreachable`    | `min`     | minutes the Horizon endpoint has not answered    |
| `horizon.paused`          | `min`     | minutes Horizon has been paused                  |
| `queue.pending`           | `job`     | jobs waiting in the queues                       |
| `queue.max_wait`          | `s`       | seconds the oldest job has been waiting          |
| `job.runtime`             | `s`       | seconds the longest-running job has been running |
| `jobs.failed_per_hour`    | `job`     | jobs failed in the last hour                     |
| `workers.missing`         | _(empty)_ | queues with waiting jobs and no worker           |

`severity` is `critical` or `warning`, as set on the rule. `summary` is one line for people,
in the panel's default language (`APP_LOCALE`): `Back within the threshold: …` once the alert
has resolved. Do not parse it, read the other fields instead. `detail` always has the same
four keys:

- `value_is_lower_bound`: `true` when there were more failed jobs than the panel reads, so
  `value` is a minimum (`jobs.failed_per_hour`)
- `longest_job` and `longest_job_queue`: the name and queue of the longest-running job
  (`job.runtime`), otherwise `null`
- `queues_without_workers`: up to 10 of the queues with waiting jobs and no worker
  (`workers.missing`), otherwise empty

A digest carries the organization's warnings in an `alerts` list, the oldest first (up to 500;
any more follow in the next digest), each in the same shape as `alert` above. A warning that
resolved since the previous digest has `resolved_at` set:

```json
{
    "version": 1,
    "event": "alert.digest",
    "delivery_id": "6b1f0c9a-3d2e-4f5a-8b7c-9d0e1f2a3b4c",
    "alerts": [
        {
            "id": "01992f41-7c2d-7e3f-8a1b-2c3d4e5f6a7b",
            "rule": "job.runtime",
            "severity": "warning",
            "summary": "Shop · production — A job has been running for 300 s",
            "value": 300,
            "threshold": 120,
            "unit": "s",
            "detail": {
                "value_is_lower_bound": false,
                "longest_job": "App\\Jobs\\ImportCatalog",
                "longest_job_queue": "imports",
                "queues_without_workers": []
            },
            "application": "Shop",
            "environment": "production",
            "opened_at": "2026-09-17T12:04:00Z",
            "resolved_at": null,
            "url": "https://horizon-watch.example.com/acme/environments/shop-production"
        }
    ],
    "organization": { "name": "Acme", "slug": "acme" },
    "sent_at": "2026-09-17T12:15:01Z"
}
```

The webhook gets every alert the panel announces, whatever the rule's **Email** setting: that
setting only decides whether the alert is also emailed. The rest of the delivery policy applies
to the webhook as to email: during quiet hours criticals still go out and the digest waits
for the end of the window; a muted alert sends nothing, its resolution included, and a muted
warning is left out of the digest, which also holds for an alert muted by a maintenance window;
a handled alert stops repeating but still sends its resolution; an environment whose collection
is paused sends nothing.

`delivery_id` names one delivery and stays the same when a failed delivery is tried again,
while `sent_at` is the time of each attempt. A receiver that answered too slowly may get the
same delivery twice: deduplicate on `delivery_id`.

Verify the signature over the raw body before parsing it, and reject old timestamps. Accept
either signature header, so that after a regeneration the receiver can move to the new secret
at any time within the 24 hours. Refuse every request when the secret is not configured,
rather than checking against an empty key:

```php
$secret = getenv('HORIZON_WATCH_WEBHOOK_SECRET');

if (! is_string($secret) || $secret === '') {
    http_response_code(500);
    exit;
}

$body = (string) file_get_contents('php://input');
$timestamp = (string) ($_SERVER['HTTP_X_HORIZON_WATCH_TIMESTAMP'] ?? '');
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
$signed = hash_equals($expected, (string) ($_SERVER['HTTP_X_HORIZON_WATCH_SIGNATURE'] ?? ''))
    || hash_equals($expected, (string) ($_SERVER['HTTP_X_HORIZON_WATCH_PREVIOUS_SIGNATURE'] ?? ''));

if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || ! $signed) {
    http_response_code(401);
    exit;
}

$payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
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

## Watching the panel

Polling, alerts and notifications all depend on the scheduler and the queue workers, so a
stopped or stuck one would silence every alert. Horizon Watch checks itself in several ways.

`GET /health` answers JSON without signing in: `200` while everything keeps up, `503` when the
database is unreachable, when the scheduler has not dispatched the due polls or repeated the
open alerts for 2 minutes, or when a queued job has waited more than 5 minutes for a worker.
Point an uptime monitor at it. The digests, sent every 15 minutes, are not part of the check: a
failed digest run is written to the log. It shows ages and counts only, nothing about the
applications you watch:

```json
{
    "status": "ok",
    "version": "v1.0.0",
    "checks": {
        "database": { "ok": true },
        "scheduler": { "ok": true, "last_run_seconds_ago": 9 },
        "queues": {
            "ok": true,
            "oldest_wait_seconds": { "polls": 2 },
            "failed_jobs_last_hour": 0
        }
    }
}
```

`/up` stays the health check of the `web` container: it answers `200` while the panel boots
and reaches its database. The `scheduler` container is healthy while it has dispatched the due
polls and repeated the open alerts in the last 2 minutes, and each `worker` and `notifier` while
it has looked for a job in the last 2 minutes; all of them also need the database.
`docker compose ps` shows the result. Docker Compose does not restart an unhealthy container by
itself, so alert on it from your monitoring. The same checks run by hand:

```bash
docker compose -f compose.prod.yaml run --rm web artisan horizon-watch:health
```

For a dead man's switch, set `HORIZON_WATCH_HEARTBEAT_URL` to the ping address of a service
such as healthchecks.io, an Uptime Kuma push monitor or a Better Stack heartbeat. The scheduler
calls it with a `GET` every minute, in a process of its own so a slow address never delays the
polls, and only while `/health` would answer `200`, so the service warns you when the panel
stops, falls behind or loses its database. `artisan horizon-watch:heartbeat` sends one by hand.
The call follows the address rules of webhooks: no redirects, link-local and cloud metadata
addresses refused, and private networks refused when `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` is
`true`. It is off by default, so the panel makes no outbound call you did not ask for.

Inside the panel, everyone signed in sees a banner while the scheduler is late or the queues
fall behind. An environment without a reading for three poll intervals is shown as _not
updated_ (or keeps its status when it was last known down) and counts as a problem on the wall,
and the dot next to _refresh every_ turns red when the page itself cannot refresh.

When php-fpm or nginx cannot start at all, the `web` container stops instead of answering 502
forever, so its restart policy takes over.

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

Docker keeps each container's log in files on the host, and `compose.prod.yaml` caps them at
five files of 10 MB per container. The health checks of `/up`, every 10 seconds, are left out of
the access log. `LOG_LEVEL` sets the lowest level the application writes, `info` by default.

## Upgrading

Releases follow [Semantic Versioning](https://semver.org/). Within a major version an upgrade
asks nothing of you: the environment variables, the services of `compose.prod.yaml`, the
`artisan` commands in this file, `GET /health` and version 1 of the webhook payload keep working
as documented. A new major version says in [CHANGELOG.md](CHANGELOG.md) what to change first.

Every image is tagged with its version (`1.0.0`), its minor version (`1.0`) and its major version
(`1`); `latest` is the newest release and never a pre-release. The `compose.prod.yaml` of each
release runs that exact version, so nothing moves until you decide. To upgrade:

1. Back up (see [Backup and restore](#backup-and-restore)).
2. Read the release's section of [CHANGELOG.md](CHANGELOG.md), its upgrade notes above all.
3. Take the `compose.prod.yaml` of the new release, then pull and restart:

```bash
curl -fsSLO https://github.com/Guard4534/horizon-watch/releases/latest/download/compose.prod.yaml
docker compose -f compose.prod.yaml pull
docker compose -f compose.prod.yaml up -d
```

To follow every release of a major version without replacing the file, set
`HORIZON_WATCH_IMAGE=ghcr.io/guard4534/horizon-watch:1`; a later release that changes
`compose.prod.yaml` says so in its upgrade notes.

Migrations run when the `web` container starts, under an advisory lock, so several replicas
can start at once. They only go forward, and an older version is not made to run on a database
a newer one has migrated: to go back, restore the backup taken before the upgrade, with the
`compose.prod.yaml` of the version it came from. Containers get 40 seconds to stop, so each
worker finishes the job it holds and php-fpm the requests it serves.

The running version shows at the bottom of the sidebar (and of the profile page on a phone), in
`artisan about` and in `GET /health`. It comes from the image: a release image carries its tag,
an image you build yourself says `dev` unless you pass `--build-arg VERSION=…`. The panel reads
Horizon and calls webhooks and the heartbeat as `HorizonWatch/<version>`.

### From 0.1.0

- `compose.prod.yaml` now refuses to start without `DB_PASSWORD`. If you never set it, your
  database still uses the old default: put `DB_PASSWORD=horizon_watch` in `.env`, upgrade, and
  then [change it](#changing-the-database-password).
- Take the new `compose.prod.yaml`: it adds the `notifier`, health checks that test the work
  instead of the process, log rotation and every new variable.
- The new file passes on variables that 0.1.0 fixed or ignored: `APP_NAME`,
  `APP_PREVIOUS_KEYS`, `LOG_LEVEL`, `SESSION_LIFETIME`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_SSLMODE`, `MAIL_SCHEME`, `MAIL_FROM_NAME` and `MAIL_TIMEOUT`. Remove from
  `.env` any of them you did not set on purpose. With the bundled database, `DB_HOST` must stay
  `postgres` and `DB_DATABASE` and `DB_USERNAME` must stay `horizon_watch`, or `web` keeps
  restarting with `database not reachable`.
- The panel no longer trusts `X-Forwarded-Host`: an nginx in front needs
  `proxy_set_header Host $host;` (see [Behind a reverse proxy](#behind-a-reverse-proxy)).
- Let the queues empty before you stop 0.1.0: invitation emails and webhooks still queued from
  it are sent the old way or fail. The upgrade notes in [CHANGELOG.md](CHANGELOG.md) list the
  rest: only instance admins create organizations, admins see every environment, and
  production environments are marked from their names.

## Backup and restore

Two things hold everything:

- the database: every application, environment, reading, rule and alert;
- the `app-data` volume: the application key. **It decrypts the secrets stored in the
  database:** the Horizon basic-auth passwords, the webhook signing secrets and the two-factor
  secrets of the accounts. Lose it and none of them can be read again; see
  [Troubleshooting](#troubleshooting) for what to set again.

Back up both, together. `pg_dump` takes a consistent copy of the database while the panel runs:

```bash
docker compose -f compose.prod.yaml exec -T postgres \
    pg_dump -U horizon_watch -d horizon_watch -Fc >horizon-watch.dump
docker run --rm -v horizon-watch_app-data:/from -v "$PWD":/to alpine \
    tar czf /to/app-data.tgz -C /from .
```

Keep both files encrypted: together they open every stored secret. The volume name is prefixed
with the Compose project name, which is the name of the directory holding `compose.prod.yaml`
unless you pass `-p`; `docker volume ls` shows the real name. Use your `DB_USERNAME` and
`DB_DATABASE` if you changed them.

Do not copy the files of the `postgres-data` volume while PostgreSQL runs: they change while they
are copied, and the copy may never start again. A copy taken with the stack stopped
(`docker compose -f compose.prod.yaml stop`) is sound, but it only starts on the same major
version of PostgreSQL, while a dump restores into any later one: dump and restore is also how
to move to a new major version.

To restore, on the same host or on a new one with the same `.env`, stop the panel if it runs,
start PostgreSQL alone, put the key back, replace the database with an empty one, load the dump
and start everything:

```bash
docker compose -f compose.prod.yaml stop web scheduler worker notifier
docker compose -f compose.prod.yaml create
docker compose -f compose.prod.yaml up -d --wait postgres
docker run --rm -v horizon-watch_app-data:/to -v "$PWD":/from alpine \
    tar xzf /from/app-data.tgz -C /to
docker compose -f compose.prod.yaml exec -T postgres psql -U horizon_watch -d postgres \
    -c 'DROP DATABASE IF EXISTS horizon_watch WITH (FORCE)' -c 'CREATE DATABASE horizon_watch'
docker compose -f compose.prod.yaml exec -T postgres \
    pg_restore -U horizon_watch -d horizon_watch --no-owner --exit-on-error <horizon-watch.dump
docker compose -f compose.prod.yaml up -d
```

The dump goes into an empty database on purpose: a database a newer version has migrated holds
tables the dump knows nothing about, and loading over it leaves them in place. If `pg_restore`
reports an error, stop there and do not start the panel: drop the database again and look at
the error before you retry.

If you would rather keep the key yourself, set `APP_KEY` in the environment: when it is set, the
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
is empty, or the server refuses the panel (_mail server refused the login_: check
`MAIL_USERNAME` and `MAIL_PASSWORD`). Set the SMTP settings and restart the stack; there is no
log fallback by design. Until then, admins can hand over invitations with **New link** on the
Members page.

**`/setup` keeps appearing.** No user exists yet, so every route redirects there. If you have
already created the administrator and still land on `/setup`, the panel is talking to an
empty database — check `DB_*` and that the `postgres` container is the one holding your
volume. Deleting the last account opens `/setup` again on purpose.

**`web` keeps restarting and its log says `database not reachable`.** The panel cannot sign in
to PostgreSQL. With the bundled database this usually means `DB_PASSWORD` changed in `.env`
after the database was created; put the old value back, or change the password in the database
too (see [Changing the database password](#changing-the-database-password)). Otherwise check
that `.env` sets no `DB_HOST`, `DB_DATABASE` or `DB_USERNAME` you did not mean.

**`/setup` asks for a setup token.** `HORIZON_WATCH_SETUP_TOKEN` is set: type its value, as it
appears in `.env`. If `/setup` says the token is too short, give it at least 16 characters in
`.env` and run `docker compose -f compose.prod.yaml up -d`.

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
  signing secret under Alert settings → Notifications and give the new one to the receiver.
  Alert emails keep going out.
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
lint, type check and unit tests, and the Pest suite.

`./vendor/bin/sail php artisan migrate:fresh --seed` fills the database with a demo
organization and a few weeks of readings, and creates an administrator:
`admin@example.com` / `password`. Never seed a database you care about.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
