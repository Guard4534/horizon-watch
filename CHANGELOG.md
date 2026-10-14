# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Instance admins. The account created by `/setup` is one, and upgrading makes the oldest
  account one. `artisan users:instance-admin {email} [--revoke]` grants or revokes the role;
  the last instance admin cannot be revoked.
- `HORIZON_WATCH_ORG_CREATION` (`admins` or `everyone`) decides who may create organizations.
- The owner of an organization can transfer ownership to another member from the Members
  page; the previous owner becomes an admin.
- A **Production environment** checkbox on environments, ticked for you when the label is
  `production`, `prod`, `prd` or `live`, alone or followed by a separator. The production mark
  shows next to the environment wherever it appears.
- A `notifier` service in `compose.prod.yaml` sends alert emails, webhooks, invitations and
  password-reset emails from their own `notifications` queue, while the `worker`s read Horizon
  from the `polls` queue.
- `MAIL_TIMEOUT` (10 seconds by default) bounds how long an unresponsive SMTP server can hold a
  send.
- Failed queue jobs are pruned after 7 days.
- `APP_PREVIOUS_KEYS` reaches the containers of `compose.prod.yaml`, so the application key can
  be rotated without losing the secrets encrypted with the old one.
- `artisan users:reset-two-factor {email}` turns two-factor authentication off for an account
  that can no longer complete it.
- `artisan secrets:reencrypt` encrypts every stored secret again with the current application
  key, so a rotated key can be removed from `APP_PREVIOUS_KEYS`.
- Self-monitoring. `GET /health` answers `503` when the database is unreachable, the scheduler
  has not dispatched the due polls or repeated the open alerts for 2 minutes, or a queued job
  has waited more than 5 minutes, for an external uptime monitor. `artisan horizon-watch:health` runs the same checks, or only
  those of a scheduler or worker container with `--role`.
- `HORIZON_WATCH_HEARTBEAT_URL`, off by default, is called every minute while the panel is
  healthy, for a dead man's switch such as healthchecks.io.
- A banner tells everyone signed in when the panel itself has stopped reading or falls behind.
- Webhook payloads carry `"version": 1`, and each alert in them a `summary` line and a
  `detail` object: the longest-running job and its queue, the queues without workers, and
  whether the failed-jobs count is a lower bound. The README documents the full contract:
  events, rule and unit values, the digest, and which alerts reach the webhook.
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
- A web app manifest and icons of the panel's own, so it can be added to a phone's home screen.
  `APP_NAME` names the panel in the browser tab, the sidebar and the installed app, and
  `compose.prod.yaml` passes it on.

### Changed

- **Upgrade note:** only instance admins can create organizations by default. Set
  `HORIZON_WATCH_ORG_CREATION=everyone` to keep the 0.1.0 behaviour.
- Deleting an account is refused while it owns an organization other people belong to.
  Organizations where it is the only member are deleted with it, and the invitations it sent
  to other organizations stay valid.
- Deleting the last account opens `/setup` again.
- People without an organization land on the organizations page instead of looping between
  the home and login pages, also right after signing in, two-factor challenge included, or
  declining an invitation. The navigation leaves out the organization pages until they join one.
- Renaming an organization or an environment keeps its slug, so bookmarks, open walls and the
  links in alerts already sent keep working.
- **Upgrade note:** download the new `compose.prod.yaml` to get the `notifier` service. With
  the previous file every worker serves every queue, notifications first.
- Containers get 40 seconds to stop and run under an init process, so a worker finishes the job
  it holds and php-fpm and nginx shut down gracefully.
- `HORIZON_WATCH_RETENTION_DAYS` is at least 7 and `HORIZON_WATCH_ALERT_RETENTION_DAYS` at
  least 1; lower values are raised to those.
- Switching organization opens the same section of the new one (wall, applications, alerts,
  alert rules, members) instead of rewriting the address of the page you were on.
- **Upgrade note:** download the new `compose.prod.yaml`: the `scheduler`, `worker` and
  `notifier` health checks now test that each one keeps working instead of only that its
  process exists, and `HORIZON_WATCH_HEARTBEAT_URL` reaches the containers.
- `/up` also checks the database.
- The `web` container stops when php-fpm or nginx cannot start, so its restart policy takes over
  instead of leaving the panel answering 502.
- **Upgrade note:** admins and the owner always see every environment. Upgrading lifts the
  visibility limits already set on admins, owners and pending admin invitations, so those
  admins start seeing, and receiving alert emails for, every environment of their
  organization; this cannot be undone by a rollback. Before upgrading, demote to member anyone
  who must stay limited. Promoting someone to admin now lifts their limit, and an admin
  invitation always grants every environment.
- **Upgrade note:** upgrading marks as production every environment labelled `production`,
  `prod`, `prd` or `live`, alone or followed by a separator (such as `prod-eu` or
  `live-preview`). Members who see everything except production stop seeing those
  environments and stop receiving their alert emails. After upgrading, review the
  **Production environment** box on each environment.
- **Upgrade note:** webhooks queued by 0.1.0, including those waiting for a retry, are sent as
  they were built, without `version`, `summary` and `detail`. If your receiver needs those
  fields, let the `notifications` queue empty before upgrading.
- The notification setting of an alert rule chooses only whether its alerts are emailed; the
  webhook receives every alert the panel announces. 0.1.0 described it as a per-rule
  notification channel.
- The account created by `/setup` starts with alert emails turned on.
- Horizon reads, webhook deliveries and heartbeats send `User-Agent: HorizonWatch/<version>`.
  Horizon reads used Guzzle's default and the other two sent `HorizonWatch`: update any
  allowlist or firewall rule that matched the old value.
- Without `MAIL_HOST`, the SMTP mailer has no host instead of falling back to `127.0.0.1`.
- **Upgrade note:** invitation codes are stored as SHA-256 hashes. Upgrading hashes the
  pending ones, so the links already sent keep working, but invitation emails still waiting in
  the queue fail. Let the queue drain before upgrading, or afterwards look for
  `App\Notifications\Teams\TeamInvitation` in `php artisan queue:failed` and resend those
  invitations. **Resend** now issues a new link each time, and the earlier one stops working.
- **Upgrade note:** in production the panel sends mail only over SMTP and `compose.prod.yaml`
  no longer passes `MAIL_MAILER` on, so no other transport, the log mailer included, can be
  chosen.
- Pages that show an environment say only whether it uses basic auth; the user name appears
  only in the edit forms.
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

### Fixed

- A name made only of symbols no longer gives an organization or application an empty slug,
  and very long names no longer overflow the slug column.
- An organization name whose slug would be a reserved address (such as `Settings!`) is refused.
- "Everything except production" hid only an environment labelled exactly `production`; it now
  hides every environment marked as production.
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
- The environment page named the masters endpoint `/horizon/api/masters` even when Horizon
  lives under another path; it now follows the environment's Horizon URL.
- The two-factor setup errors and the loading indicator were always in English.
- The browser tab kept the name the image was built with instead of following `APP_NAME`.
- Controls drawn by the browser, such as scrollbars, checkboxes and autofilled fields, were
  light on the dark pages.
- On the day the clocks go forward, the time axis of the charts showed the first hour after the
  change twice.

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
