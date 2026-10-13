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
