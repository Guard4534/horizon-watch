# Product

## What it is

Horizon Watch is a self-hosted control room for several Laravel Horizon installations. It polls each
installation's Horizon HTTP API, stores the readings, evaluates alert rules, and notifies by email and
signed webhook. It only ever reads; retries and job management stay in each application's own
Horizon dashboard.

## Users and context

Developers and operators of teams that run several Laravel applications, each with several
environments. Three contexts, all first-class:

- **Shared monitor / TV**: the status wall stays open on a screen and is read from a few metres
  away. Status must be legible at a distance; nothing may look alarming unless it is.
- **Desktop while working**: a tab next to the IDE, glanced at between tasks and opened properly
  when an alert arrives.
- **Mobile on call**: opened from a phone after a notification, often at night, to understand in
  seconds what is wrong and whether it needs action now.

## Primary jobs

1. See at a glance whether anything is wrong, and where.
2. Understand one environment's problem: which queue, since when, how bad.
3. Tune thresholds and notifications so alerts are rare and real.
4. Manage who sees which environments.

## Brand personality

Calm, precise, reliable. A control room, not a dashboard toy: no visual alarm until there is a real
problem, and when there is one it is unmistakable.

## Anti-references

- Generic SaaS: identical card grids, gradients, hero-metric landing-page templates.
- Neon "hacker" looks: phosphor green, glows, movie-terminal aesthetics.
- Chromatic alarmism: red, amber or green used as decoration, so that a real failure no longer
  stands out.

## Design principles

1. **Status colours are reserved.** Red, amber and green mean down, warning and healthy, and
   nothing else. Identity colours (environments, applications, accents) come from a cool range that
   excludes those hues: blue, indigo, violet, cyan, slate, rose-violet.
2. **Quiet by default.** Healthy things recede; problems carry the colour, the weight and the motion.
3. **One signal, one encoding.** Each fact is shown once, in one visual channel; no stacking of
   stripe + dot + tint for the same thing.
4. **Legible at a distance.** Few type sizes with real contrast between them; no body text below
   11–12px; uppercase tracked labels only where they earn it.
5. **Dense but honest.** Show the numbers that matter with units and freshness; never truncate the
   primary reading; put secondary detail one step away.

## Accessibility

WCAG 2.2 AA: text contrast ≥ 4.5:1 (≥ 3:1 for large text and UI glyphs), status never conveyed by
colour alone, full keyboard operation, visible focus, `prefers-reduced-motion` respected. English and
Italian interface.
