# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The first stable release. From here on Horizon Watch follows Semantic Versioning: within 1.x the
environment variables, the services of `compose.prod.yaml`, the documented `artisan` commands,
`GET /health` and version 1 of the webhook payload keep working. Coming from 0.1.0, read the
**Upgrade notes** below and the README's _Upgrading_ section first, take the new
`compose.prod.yaml` and back up before you start.

### Added

- Maintenance windows silence the alerts of an environment, an application or the whole
  organization for up to 31 days while readings go on. Plan one under Alert settings, or use
  **Silence for…** on an environment page for 30 minutes to 4 hours. The wall and the
  environment page mark environments in maintenance.
- Threshold rules can wait for the breach to last up to 60 minutes (**for … min**) before they
  open an alert, so a value hovering around its threshold no longer opens and resolves an alert
  at every reading. The duration can be overridden per environment, like the other settings.
- Instance admins. The account created by `/setup` is one, and upgrading makes the oldest
  account one. `artisan users:instance-admin {email} [--revoke]` grants or revokes the role;
  the last instance admin cannot be revoked.
- `HORIZON_WATCH_ORG_CREATION` (`admins` or `everyone`) decides who may create organizations.
- The owner of an organization can transfer ownership to another member from the Members
  page; the previous owner becomes an admin.
- Each environment has a **type**: Production, Pre-production, Staging, Development, Testing or
  Demo. The type gives the environment its colour, puts the production mark wherever the
  environment appears and decides what "everything except production" hides. The forms suggest
  the type from the name (`production`, `prod`, `prd` and `live` suggest Production) until you
  pick one.
- `HORIZON_WATCH_SETUP_TOKEN`: when set, `/setup` asks for it before it creates the first
  account. It must be at least 16 characters long.
- Self-monitoring. `GET /health` answers `503` when the database is unreachable, the scheduler
  has not dispatched the due polls or repeated the open alerts for 2 minutes, or a queued job
  has waited more than 5 minutes, for an external uptime monitor. `artisan horizon-watch:health`
  runs the same checks, or only those of a scheduler or worker container with `--role`.
- `HORIZON_WATCH_HEARTBEAT_URL`, off by default, is called every minute while the panel is
  healthy, for a dead man's switch such as healthchecks.io.
- A banner tells everyone signed in when the panel itself has stopped reading or falls behind.
- Webhook payloads carry `"version": 1`, and each alert in them a `summary` line and a
  `detail` object: the longest-running job and its queue, the queues without workers, and
  whether the failed-jobs count is a lower bound. The README documents the full contract:
  events, rule and unit values, the digest, and which alerts reach the webhook.
- After the webhook secret is regenerated, deliveries carry `X-Horizon-Watch-Previous-Signature`,
  signed with the old secret, for 24 hours, so the receiver can switch at its own pace.
- The delivery log says why an alert email or a webhook did not go out: the mail server was
  unreachable, refused the login or refused the message, or the receiver answered with a given
  HTTP status.
- The running version shows at the bottom of the sidebar, in `artisan about` and in
  `GET /health`, and Horizon reads, webhooks and heartbeats identify themselves as
  `HorizonWatch/<version>`.
- While no SMTP server is set, admins can create a new link for a pending invitation from the
  Members page and hand it over themselves; instance admins can do so at any time. Each new
  link replaces the earlier ones, the emailed one included.
- The Members page and the notification settings warn while no SMTP server is set.
- The wall, the alerts page and the notification settings warn when alerts reach nobody: no
  member receives alert emails, and there is neither an extra address nor a webhook. The
  delivery policy says how many members receive alert emails.
- Security settings can log out every other session of the account.
- `artisan users:reset-two-factor {email}` turns two-factor authentication off for an account
  that can no longer complete it.
- `artisan secrets:reencrypt` encrypts every stored secret again with the current application
  key, so a rotated key can be removed from `APP_PREVIOUS_KEYS`.
- A `notifier` service in `compose.prod.yaml` sends alert emails, webhooks, invitations and
  password-reset emails from their own `notifications` queue, while the `worker`s read Horizon
  from the `polls` queue.
- `compose.prod.yaml` passes on `APP_PREVIOUS_KEYS`, so the application key can be rotated
  without losing the secrets encrypted with the old one, and `DB_HOST`, `DB_PORT`,
  `DB_DATABASE`, `DB_USERNAME`, `DB_SSLMODE`, `LOG_LEVEL`, `SESSION_LIFETIME`, `MAIL_SCHEME` and
  `MAIL_FROM_NAME`, so the panel can use a database of your own without editing the file. The
  README shows how to leave the bundled PostgreSQL out.
- `MAIL_TIMEOUT` (10 seconds by default) bounds how long an unresponsive SMTP server can hold a
  send.
- Failed queue jobs are pruned after 7 days.
- Error pages for 403, 404, 419, 500 and 503 in the panel's style and language, and toasts on
  the sign-in pages.
- The environment page lists every open alert, muted ones included, and the application page
  shows muted alerts too.
- The open-alert count shows in the browser tab title and on the phone's tab bar.
- A web app manifest and icons of the panel's own, so it can be added to a phone's home screen.
  `APP_NAME` names the panel in the browser tab, the sidebar and the installed app, and
  `compose.prod.yaml` passes it on.
- The README covers reverse proxies (Caddy and nginx, HSTS), the default alert rules, roles
  and visibility, sizing, backups with `pg_dump`, upgrading and the compatibility promise.

### Changed

- A calmer interface. Environment colours follow the environment type and come from a cool range
  (rose-violet, violet, indigo, blue, slate, cyan), so red, amber and green only ever mean down,
  warning and healthy. Status is shown once per tile and card, in words with an icon, without
  side stripes or glowing lamps; healthy readings stay neutral. Text is never smaller than 11px
  and uses fewer uppercase labels. Rules are shown by name ("Horizon inactive ≥ 2 min") instead
  of their id, the throughput and max-wait chart is split into two charts with one scale each,
  environment overrides use one Customize switch per rule, and the panel has a new brand mark
  and icons.
- Durations read the same everywhere: rounded down, with at most two units, each spaced from
  its number (`45 s`, `1 min 30 s`, `1 h 5 min`, `2 d 3 h`, a day from 24 hours), on the panel,
  in alert emails and in the webhook `summary` (`A job has been running for 5 min`). Only
  chart axes keep a compact form. Thresholds keep the unit they are set in
  (`Max wait > 60 s`), thresholds and alert values are grouped the way the language writes
  numbers, and a time on another day shows its weekday, day and month. In the webhook payload
  only the wording of `summary` changes: `value`, `threshold` and `unit` are the same numbers
  as before.
- An environment's Horizon URL, as you open it in the browser, is the only address the panel
  asks for. Applications no longer have a domain: the one shown next to an application is the
  host of its production environment's Horizon URL, or of its first environment. The wizard asks
  for the full Horizon URL of the first environment and suggests the others from it, and **Add
  environment** suggests one from the application's production environment.
- Horizon URLs are tidied when saved and when tested: surrounding spaces, capitals in the
  scheme and host, trailing slashes and a trailing `/api` or Horizon API path (such as
  `/api/stats`) are removed.
- The wall's environment chips filter by environment type instead of by environment name, and
  the wall link carries `?type=` instead of `?environment=`.
- **Upgrade note:** `compose.prod.yaml` refuses to start without `DB_PASSWORD`, which used to
  default to `horizon_watch`. If you never set it, put `DB_PASSWORD=horizon_watch` in `.env`
  before upgrading, then change it as the README describes.
- **Upgrade note:** `compose.prod.yaml` now passes on from `.env` variables that 0.1.0 fixed or
  ignored: `APP_NAME`, `APP_PREVIOUS_KEYS`, `LOG_LEVEL`, `SESSION_LIFETIME`, `DB_HOST`,
  `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_SSLMODE`, `MAIL_SCHEME`, `MAIL_FROM_NAME` and
  `MAIL_TIMEOUT`. Before upgrading, remove from `.env` any of them you did not set on purpose,
  such as values left over from `.env.example`: with the bundled database `DB_HOST` must stay
  `postgres` and `DB_DATABASE` and `DB_USERNAME` must stay `horizon_watch`, or `web` keeps
  restarting with `database not reachable`, and a changed `APP_NAME` signs everyone out.
- **Upgrade note:** only instance admins can create organizations by default. Set
  `HORIZON_WATCH_ORG_CREATION=everyone` to keep the 0.1.0 behaviour.
- **Upgrade note:** download the new `compose.prod.yaml`. It adds the `notifier` service (with
  the previous file every worker serves every queue, notifications first), health checks of the
  `scheduler`, `worker` and `notifier` that test that each one keeps working instead of only that
  its process exists, log rotation, and the new variables.
- **Upgrade note:** admins and the owner always see every environment. Upgrading lifts the
  visibility limits already set on admins, owners and pending admin invitations, so those
  admins start seeing, and receiving alert emails for, every environment of their
  organization; this cannot be undone by a rollback. Before upgrading, demote to member anyone
  who must stay limited. Promoting someone to admin now lifts their limit, and an admin
  invitation always grants every environment.
- **Upgrade note:** each environment's colour becomes its type. An environment coloured `prod`,
  or labelled `production`, `prod`, `prd` or `live`, alone or followed by a separator (such as
  `prod-eu` or `live-preview`), becomes Production: members who see everything except production
  stop seeing it and stop receiving its alert emails. The other colours become the type of the
  same name (`develop` becomes Development) and `worker` becomes Staging. Stored alerts of an
  environment that becomes Production become Production too; the others keep the type that
  matches their colour. After upgrading, review the type of each environment.
- **Upgrade note:** a bookmarked wall link that filters with `?environment=` shows every
  environment after the upgrade. Change it to the type, such as `?environment=production` to
  `?type=production`.
- **Upgrade note:** applications lose their domain field. Nothing else is lost: each environment
  keeps its Horizon URL, and the domain shown next to the application now comes from those URLs.
- **Upgrade note:** webhooks queued by 0.1.0, including those waiting for a retry, are sent as
  they were built, without `version`, `summary` and `detail`. If your receiver needs those
  fields, let the `notifications` queue empty before upgrading.
- **Upgrade note:** the panel no longer trusts `X-Forwarded-Host` and builds every link in an
  email from `APP_URL`. An nginx in front of it needs `proxy_set_header Host $host;`.
- **Upgrade note:** in production the panel sends mail only over SMTP and `compose.prod.yaml`
  no longer passes `MAIL_MAILER` on, so no other transport, the log mailer included, can be
  chosen. Password reset emails go through the queue.
- Releases are built only after every CI job, the smoke tests included, has passed on the tag.
  `latest` follows stable releases only, and the release notes are the matching section of this
  file. The `compose.prod.yaml` attached to a release runs that exact version, and the README
  installs it from the latest release instead of from `main`.
- CI also upgrades the previous release to the new image, and both smoke tests wait for a
  successful reading of a stub Horizon to be stored.
- The health checks of `/up` no longer fill the nginx access log, and container logs are
  rotated at five files of 10 MB each.
- `LOG_LEVEL` defaults to `info` in `compose.prod.yaml` instead of `debug`.
- Deleting an account is refused while it owns an organization other people belong to.
  Organizations where it is the only member are deleted with it, and the invitations it sent
  to other organizations stay valid.
- Deleting the last account opens `/setup` again.
- Deleting an organization takes effect at once and its readings are deleted in the background;
  deleting an application or an environment deletes its readings in chunks.
- People without an organization land on the organizations page instead of looping between
  the home and login pages, also right after signing in, two-factor challenge included, or
  declining an invitation. The navigation leaves out the organization pages until they join one.
- Renaming an organization or an environment keeps its slug, so bookmarks, open walls and the
  links in alerts already sent keep working.
- Switching organization opens the same section of the new one (wall, applications, alerts,
  alert rules, members) instead of rewriting the address of the page you were on, and a page
  that refreshes itself no longer switches anyone's organization.
- Containers get 40 seconds to stop and run under an init process, so a worker finishes the job
  it holds and php-fpm and nginx shut down gracefully.
- `HORIZON_WATCH_RETENTION_DAYS` is at least 7 and `HORIZON_WATCH_ALERT_RETENTION_DAYS` at
  least 1; lower values are raised to those.
- `/up` also checks the database.
- The `web` container stops when php-fpm or nginx cannot start, so its restart policy takes over
  instead of leaving the panel answering 502.
- The poll interval moves in steps of 15 seconds, the pace at which the scheduler queues
  readings.
- Horizon's per-queue runtimes are read again only every 5 minutes, or when a new queue appears,
  instead of at every reading.
- A failed reading and **Test connection** say why they failed: a timeout, a refused connection,
  a TLS error, the HTTP status or the redirect target.
- A redirect or a 4xx answer from a webhook receiver, other than 408 and 429, is logged as
  failed at once instead of being tried three times; a 429 is retried after its `Retry-After`,
  up to 5 minutes.
- A resolution goes only to the addresses and the webhook host that received the alert while it
  was open, and an alert that resolves before its notice goes out is not announced at all.
- The notification setting of an alert rule chooses only whether its alerts are emailed; the
  webhook receives every alert the panel announces. 0.1.0 described it as a per-rule
  notification channel.
- The account created by `/setup` starts with alert emails turned on.
- Horizon reads, webhook deliveries and heartbeats send `User-Agent: HorizonWatch/<version>`.
  Horizon reads used Guzzle's default and the other two sent `HorizonWatch`: update any
  allowlist or firewall rule that matched the old value.
- Without `MAIL_HOST`, the SMTP mailer has no host instead of falling back to `127.0.0.1`.
- Invitation emails are written in the language of whoever sends or resends them, instead of
  always in `APP_LOCALE`.
- Abbreviated counts use the decimal separator of the language, such as `1,2k` in Italian.
- The wall calls its pending total **Pending jobs**, like the alert rule, instead of
  **Queued jobs**.
- The favicon and the home-screen icon are the mark of the panel instead of the Laravel logo.
- Search engines are asked to stay out: `robots.txt` disallows everything and every page says
  `noindex`.
- PostgreSQL is the only database the configuration offers, and `DB_CONNECTION` defaults to
  `pgsql`. The polling times of environments and the times of invitations are stored with
  their time zone, like every other time.
- The settings, organization and two-factor screens use the same fields, buttons and cards as
  the rest of the panel.
- Pages load faster: missing indexes, memberships read once per request, and environment pages
  that load only their own readings.

### Fixed

- Reloading `/setup` is no longer rate limited: only submitting it is, so a few refreshes on a
  fresh install no longer end in an HTTP 429 page.
- Horizon inactive and Horizon paused alerts resolved when a single reading failed, and their
  clock started again; a failed reading now leaves them as they are.
- Quiet hours that covered the whole day silently turned quiet hours off; they are refused.
- The failed-jobs rule stopped counting at 50, because Horizon lists jobs 50 at a time, and the
  job-runtime rule could miss a long-running job on a busy queue. Horizon Watch now reads up to
  4 pages of failed jobs and the oldest pending jobs.
- An address copied from a Horizon screen, such as `/horizon/dashboard`, failed as "not
  Horizon"; **Test connection** now suggests the dashboard URL.
- An extra alert address that also belonged to a member with limited visibility received only
  that member's alerts.
- One organization with many held or undeliverable alerts could delay the alerts of the others.
- A resolution was sent for an alert whose opening had been dropped.
- Alert emails showed the wrong comparison, the rule's internal name and untranslated units.
- Changing the email address to one already taken, in different capitals, answered 500.
- A name made only of symbols no longer gives an organization or application an empty slug,
  and very long names no longer overflow the slug column.
- An organization name whose slug would be a reserved address (such as `Settings!`) is refused.
- "Everything except production" hid only an environment labelled exactly `production`; it now
  hides every environment of type Production.
- A reading that crashed or ran past its time limit left nothing behind, so the
  endpoint-unreachable rule never fired; it is now stored as unreachable.
- A reading dropped because it waited in the queue longer than its interval is now logged.
- A single connection error or 502, 503 or 504 from Horizon no longer turns the tile red: the
  request is tried once more.
- Pruning no longer deletes the last reading of an environment paused for longer than the
  retention, which made its tile show zeros.
- With a different application key, an environment whose basic-auth password can no longer be
  decrypted stopped being read without saying why, and its edit page answered 500. It is now
  read as failed, with its own error that asks for the password again, and the password can be
  typed again.
- With a different application key, a webhook signing secret that could no longer be decrypted
  stopped every alert notification of the organization, emails included. Emails now go out,
  the webhook delivery is logged as not delivered with its own error, and regenerating the
  secret works.
- With a different application key, the two-factor challenge answered 500. It now says the
  secret can no longer be read and asks for an administrator.
- An environment whose readings stopped arriving kept its last status, so it could stay green,
  out of the **Problems** filter and counted as up. It is now shown as _not updated_, unless it
  was last known down, and counts as a problem everywhere.
- The live dot in the header stayed green while the page could not refresh, and a failed
  refresh could cover a wall left on a screen with an error dialog. The dot now turns red and
  the error is not shown for automatic refreshes.
- Start times of long-running jobs were in UTC; they are in the viewer's time zone, like every
  other time. Long waits read in hours and days instead of hundreds of minutes.
- Changing the range of a chart could be overwritten by a refresh still in flight.
- The phone's **Up** figure counted differently from the desktop's **Environments up**.
- The queue table and the application search had no empty state, and the environment page kept
  two columns on narrow desktop windows.
- Typing the wrong name in a confirmation dialog and pressing Enter still submitted it.
- Two-factor recovery codes were loaded and kept on the page while hidden; they are now loaded
  only when shown.
- `APP_LOCALE` had no effect on the web pages: whoever had not chosen a language and whose
  browser asked for neither English nor Italian got English. They now get `APP_LOCALE`.
- In Italian, the password reset email, the greeting, signature and footer of every email,
  the two-factor and password confirmation errors and the name of the current password field
  were in English.
- Counts read `1 master`, `1 worker` and `3 queues` instead of `1 masters`, `1 workers` and
  `3 queue`, and alerts read `1 job pending` and `1 job failed in the last hour` instead of
  `1 jobs …`, in the alert emails and webhook summaries too.
- Pages in Italian no longer show English text for a moment while they load.
- Messages about creating, renaming, leaving or deleting an organization, and the errors about
  reserved names and existing members, said "team".
- Italian used both "failed job" and "job falliti" for the same thing; it now always says
  "job falliti".
- The two-factor setup errors and the loading indicator were always in English.
- The browser tab kept the name the image was built with instead of following `APP_NAME`.
- Controls drawn by the browser, such as scrollbars, checkboxes and autofilled fields, were
  light on the dark pages.
- On the day the clocks go forward, the time axis of the charts showed the first hour after the
  change twice.
- Accessibility: faint text meets WCAG AA contrast, animations stop when the system asks for
  reduced motion, pages have a top-level heading, a skip link and named navigation, form errors
  are tied to their fields, search fields and filters are labelled, and the charts can be read
  with the keyboard.

### Security

- Password reset links are always built from `APP_URL`, so a forged `Host` header cannot point
  them elsewhere.
- Changing the email address needs the current password, and the old address gets a notice.
- Changing or resetting the password logs out every other session of the account.
- The forgot-password form answers the same way whether an account exists or not, and is rate
  limited per address and per account. Sign-in also stops an account for the rest of the hour
  after 30 failed attempts, from whatever address, and every form that checks the current
  password is rate limited.
- Invitation codes are stored as SHA-256 hashes. **Upgrade note:** upgrading hashes the pending
  ones, so the links already sent keep working, but invitation emails still waiting in the
  queue fail. Let the queue drain before upgrading, or afterwards look for
  `App\Notifications\Teams\TeamInvitation` in `php artisan queue:failed` and resend those
  invitations. **Resend** now issues a new link each time, and the earlier one stops working.
- Pages that show an environment say only whether it uses basic auth; the user name appears
  only in the edit forms.
- The Horizon reader refuses compressed answers and bounds what it keeps: at most 500 queues,
  200 masters, supervisors and jobs per list and 4 pages of jobs per reading, all within the
  read budget, so a hostile or huge Horizon cannot exhaust a worker.
- Link-local and cloud metadata addresses always refused now include Google Cloud's IPv6
  metadata address and Azure's WireServer, and `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` also
  refuses carrier-grade NAT (used by Tailscale), unique-local IPv6 and benchmarking addresses.
- The environment forms warn when basic-auth credentials would travel over `http://`.
- Moving the webhook to another host issues a new signing secret, shown once, so the old
  receiver cannot sign for the new one.
- `/setup` can require `HORIZON_WATCH_SETUP_TOKEN`, and `DB_PASSWORD` no longer has a default
  (see **Changed**).
- A Horizon URL or a webhook address that points to a network the panel never calls
  (link-local and cloud metadata addresses, and private networks when
  `HORIZON_WATCH_BLOCK_PRIVATE_NETWORKS` is on) is refused when saved, without looking names up.

## [0.1.0] - 2026-09-29

First public release.

### Added

- Applications, environments and organizations, with invitation-only membership and
  per-person environment visibility.
- Polling of the Horizon HTTP API of every configured environment, with stored readings,
  throughput and wait-time series, and a wall that shows every environment at a glance.
- Alert rules per organization and per environment: master inactive, endpoint unreachable,
  Horizon paused, pending jobs, maximum wait, job runtime, failed jobs per hour and missing
  workers, each with its own threshold, severity and notification channel.
- Alert notifications by email and by signed webhook, with a digest for warnings, repeat
  notifications while an alert stays open, and a delivery log.
- A production image that runs nginx, php-fpm, the scheduler and the queue workers, with
  `compose.prod.yaml` for a full stack on PostgreSQL.
- English and Italian interface.

[Unreleased]: https://github.com/Guard4534/horizon-watch/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Guard4534/horizon-watch/releases/tag/v0.1.0
