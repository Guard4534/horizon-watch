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
- Switching organization opens the same section of the new one (wall, applications, alerts,
  alert rules, members) instead of rewriting the address of the page you were on.
- **Upgrade note:** admins and the owner always see every environment. Upgrading lifts the
  visibility limits already set on admins, owners and pending admin invitations, so those
  admins start seeing, and receiving alert emails for, every environment of their
  organization; this cannot be undone by a rollback. Before upgrading, demote to member anyone
  who must stay limited. Promoting someone to admin now lifts their limit, and an admin
  invitation always grants every environment.

### Fixed

- A name made only of symbols no longer gives an organization or application an empty slug,
  and very long names no longer overflow the slug column.
- An organization name whose slug would be a reserved address (such as `Settings!`) is refused.

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
