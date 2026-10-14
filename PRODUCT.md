# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Developers and operators in teams that run several Laravel applications, each with several
environments. Horizon Watch is open source (MIT) and published for any such team to install and
run on their own: the people who use it have never met its author, so the first run, the
interface and the README have to stand on their own.

Inside one installation, people hold one of four organization roles (viewer, member, admin,
owner), and the account created at setup is also the instance admin. Viewers and members may see
only part of the environments (for example everything except production).

Three usage contexts, all first-class:

- **Shared monitor / TV**: the status wall stays open on a screen and is read from a few metres
  away. Status must be legible at a distance; nothing may look alarming unless it is.
- **Desktop while working**: a tab next to the IDE, glanced at between tasks and opened properly
  when an alert arrives.
- **Mobile on call**: opened from a phone after a notification, often at night, to understand in
  seconds what is wrong and whether it needs action now.

## Product Purpose

Horizon Watch is a self-hosted control room for several Laravel Horizon installations. It polls
each installation's Horizon HTTP API, stores the readings, evaluates alert rules, opens and
resolves alerts, and notifies by email and signed webhook. Retries and job management stay in
each application's own Horizon dashboard.

Primary jobs:

1. See at a glance whether anything is wrong, and where.
2. Understand one environment's problem: which queue, since when, how bad.
3. Tune thresholds and notifications so alerts are rare and real.
4. Manage who sees which environments.

Success means a team stops opening N Horizon dashboards one by one, and an alert from Horizon
Watch is trusted enough to act on.

## Positioning

What a neighbouring product (Horizon itself, Laravel Pulse, Grafana/Prometheus, SaaS monitoring)
cannot truthfully claim all at once:

- **One view across many applications and environments**: a single control room instead of one
  Horizon dashboard per application.
- **Read-only, HTTP only**: it never writes to Horizon and never talks to Redis; every number
  comes from Horizon's own JSON API, so it is safe to point at production.
- **Self-hosted with no outbound calls**: one Docker image, no CDNs, remote fonts or external
  lookups; the data stays with the team that runs it.
- **No agent in the monitored applications**: nothing to install in them; Horizon only has to be
  reachable over HTTP (with basic auth if wanted).

## Operating Context

- A typical installation watches **10 to 50 environments** across several applications (the demo
  data has 9 applications and 29 environments). The wall must stay readable at that density;
  grouping by application and filtering matter.
- Runs as `compose.prod.yaml`: `web`, `scheduler`, two polling `worker`s, a `notifier` and
  PostgreSQL 18, usually behind a TLS reverse proxy.
- Notifications go by email (SMTP) and signed webhook. Chat channels (Slack, Discord, Teams) are
  deliberately not part of 1.0.
- Alert behaviour that users rely on: severities (warning, critical), sustained thresholds,
  quiet hours, repeat intervals, digests of warnings, muting, "handled" marks, and maintenance
  windows that silence alerts without stopping readings.
- The panel watches itself: a stopped scheduler or worker is reported on every page and on
  `/health`.

## Capabilities and Constraints

- Interface in English and Italian; `APP_LOCALE` sets the default.
- Organizations are created only by instance admins by default
  (`HORIZON_WATCH_ORG_CREATION=admins|everyone`).
- "Everything except production" visibility uses an explicit production flag on each
  environment.
- Out of scope for 1.0, deferred on purpose: chat channels, per-organization API tokens, an
  update check, maintenance start/end webhook events, a CI job against a real Horizon.

## Brand Commitments

Personality: calm, precise, reliable. A control room, not a dashboard toy: no visual alarm until
there is a real problem, and when there is one it is unmistakable.

Binding constraints set by the owner:

- **Status colours are reserved.** Red, amber and green mean down, warning and healthy, and
  nothing else. Identity colours (environments, applications, accents) come from a cool range
  that excludes those hues.
- **Quiet by default, one signal per fact.** Healthy things recede; a fact is shown once, in one
  channel, not stacked as stripe plus dot plus tint.
- **Legible at a distance.** No interface text below 11px; uppercase tracked labels only where
  they earn it.

Anti-references:

- Generic SaaS: identical card grids, gradients, hero-metric landing-page templates.
- Neon "hacker" looks: phosphor green, glows, movie-terminal aesthetics.
- Chromatic alarmism: red, amber or green used as decoration, so that a real failure no longer
  stands out.

Assets: the name "Horizon Watch" (overridable per instance through `APP_NAME`), the brand mark in
`resources/js/components/nocturne/BrandMark.vue` and `public/favicon.svg` with the icons derived
from it.

## Evidence on Hand

- README screenshots in `.github/screenshots/` (`wall.png`, `environment.png`,
  `alert-rules.png`). They date from 0.1.0 (2026-09-29) and predate the 1.0 interface, so they
  must be retaken before they are used again.
- Synthetic demo data from `php artisan migrate:fresh --seed` (`database/seeders/`): 9 fictional
  applications on `example.com`/`.net`/`.org` hosts, readings and alerts. It is demo material, not
  real usage.
- There are no customers, testimonials, adoption figures or benchmarks. None may be invented.

## Product Principles

1. **Trust over coverage.** An alert is worth sending only if people will act on it; prefer
   fewer, real alerts and honest freshness ("last reading 8 min ago") over more signals.
2. **Never touch what is watched.** Every feature must keep the read-only, HTTP-only, no-agent
   promise; anything that would write to Horizon or Redis belongs in Horizon itself.
3. **Self-sufficient for strangers.** A team that finds the project on GitHub must get from
   `docker compose up` to a trustworthy wall without help: clear first run, clear errors, no
   hidden dependencies.
4. **Dense but honest.** Show the numbers that matter with units and freshness; never truncate
   the primary reading; put secondary detail one step away.
5. **Least privilege by default.** Visibility and roles narrow what each person sees and can do;
   production stays hideable from people who do not need it.

## Accessibility & Inclusion

WCAG 2.2 AA: text contrast ≥ 4.5:1 (≥ 3:1 for large text and UI glyphs), status never conveyed by
colour alone, full keyboard operation, visible focus, `prefers-reduced-motion` respected. English
and Italian interface.
