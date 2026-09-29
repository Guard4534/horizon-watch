# Contributing

Thanks for taking the time. This file is what a new contributor needs to get a working
environment, find where code goes, and avoid the handful of traps this project has collected.

## Stack

- PHP 8.5, Laravel 13, Inertia 3 with Vue 3 and TypeScript, Tailwind 4, shadcn-vue,
  Wayfinder, Fortify.
- PostgreSQL 18 for data, queue, cache and sessions. No Redis.
- `spatie/laravel-data` for every page prop and every validated input. TypeScript types are
  generated from those classes into `resources/js/generated/generated.d.ts` by
  `php artisan typescript:transform`.
- `laravel-vue-i18n`. English source strings are the keys; `lang/it.json` holds the Italian.
- The Nocturne design system lives in `resources/css/nocturne.css`, every class prefixed
  `nc-`. Phosphor icons only.

## Getting started

Development runs in Sail. Always start it through the `sail` script:

```bash
composer install
./vendor/bin/sail up -d
./vendor/bin/sail exec pgsql createdb -U sail testing
./vendor/bin/sail composer run setup
./vendor/bin/sail composer run dev
```

`composer run setup` copies `.env.example` to `.env`, generates `APP_KEY`, migrates and
builds the assets. Running those steps by hand and skipping `key:generate` is the most common
reason the first request fails.

The stack is `laravel.test`, `pgsql`, `mailpit`, `queue` and `scheduler`. Mailpit catches
every email the app sends; its inbox is at <http://localhost:8025>. The `queue` worker is not
optional when you test the invitation flow by hand: the notification is `ShouldQueue` on the
database queue, so without a worker the email never leaves the `jobs` table. The `scheduler`
queues the due readings every 15 seconds.

For a database with something in it:

```bash
./vendor/bin/sail php artisan migrate:fresh --seed
```

That creates a demo organization with a few weeks of readings and an administrator,
`admin@example.com` / `password`.

## Commands

```bash
./vendor/bin/sail composer ci:check     # everything CI runs
./vendor/bin/sail composer lint         # Pint, writing
./vendor/bin/sail composer test         # Pest only
./vendor/bin/sail npm run check         # front-end lint and formatting
./vendor/bin/sail npm run types:check   # regenerates TS types, then vue-tsc
./vendor/bin/sail npm run build
./bin/smoke-prod.sh                     # builds the production image, checks a fresh install
```

`composer ci:check` is the single entry point: Pint, Larastan, the front-end lint, the
front-end type check and the Pest suite, in that order. Run it before opening a pull request.

## Where code goes

- `app/Actions/` — one class per user intention, with a `handle()` method.
- `app/Queries/` — one class per page, returning that page's Data object.
- `app/Data/` — `laravel-data` classes. A page receives exactly one prop, `page`.
- `app/Monitoring/` — `MonitoringRepository` and its implementations.
- `app/Externals/` — clients for systems outside this one. No domain rules there.
- Controllers stay thin: a validated Data object in, one action or query call, a response.

No generic service classes.

## Conventions

- Code, identifiers, comments, commit messages and documentation in English.
- Conventional Commits, one line.
- Comments are annotations only: PHPDoc tags (`@param`, `@return`, `@var`, `@property`,
  `@template`…), type annotations and tool directives (`@phpstan-ignore`, lint or formatter
  directives, `@ts-expect-error`). No prose, no descriptions, no decision notes — if
  something needs explaining, it belongs here or in the pull request.
- No real company names, hostnames, credentials or personal email addresses anywhere in the
  repository. Use `example.com`, `example.net`, `example.org` and `*.internal`.
- The panel must run without internet access: no CDNs, no remote fonts, no external lookups
  (`Password::uncompromised()` among them).
- Nothing that could carry a credential is ever logged, attached to an exception, or shown.

## Tests

Tests run on PostgreSQL, against the `testing` database. To run a suite in parallel with
another one, point it at a database of its own:

```bash
./vendor/bin/sail exec pgsql createdb -U sail testing_two
docker compose exec -u sail -e DB_DATABASE=testing_two laravel.test php artisan test
```

`tests/Feature/Horizon/LiveHorizonTest.php` is skipped unless `HORIZON_WATCH_LIVE_URL` points
at a real Horizon dashboard. Never set it in CI.

## Traps

These have all cost someone an afternoon.

**Environment and Docker**

- Start the development stack only with `./vendor/bin/sail up -d`. A plain
  `docker compose up` recreates the containers without `WWWUSER`, the `sail` user keeps uid
  1337, and every page answers 500 because `storage/` is not writable. Fix it with
  `sail up -d --force-recreate`.
- The `queue` and `scheduler` services run through the image's supervisord, not a `command:`.
  `start-container` hands the command to gosu with `$WWWUSER`, which is unset outside the
  sail script.
- `bin/smoke-prod.sh` runs Compose with `--env-file /dev/null` on purpose: the development
  `.env` sets `APP_KEY`, and an operator-supplied key stops the entrypoint from writing
  `/data/app-key`, which is one of the things the smoke test checks.
- Laravel's `AddLinkHeadersForPreloadedAssets` sends one `Link` header listing every
  preloaded chunk of the page. On the busy pages it passes nginx's default 4 KiB FastCGI
  buffer and the production image answers 502, `upstream sent too big header`.
  `docker/prod/nginx.conf` raises the FastCGI buffers; keep them if the header grows.
- `docker/prod/nginx.conf` sets the security headers through an included snippet, because
  `add_header` inside a `location` block replaces the inherited ones instead of adding to
  them. Any new `location` that sets a header of its own must include the snippet too.

**Laravel**

- Fortify's routes redirect to `/setup` while no user exists: a test that opens the login
  page has to create a user first.
- The setup action takes a PostgreSQL advisory lock; it will not work on SQLite.
- `DispatchDuePolls::handle()` must not run inside a transaction: the database cache lock on
  the same connection would abort it.
- The schedule has a sub-minute event, so `schedule:run` keeps repeating until the end of the
  minute. Never call it in a test with a frozen clock — it never returns. Use
  `schedule:test`.
- `Team` uses soft deletes, so database cascades never fire from it: delete its applications
  explicitly before the team row.
- `environment_user` is keyed by `user_id`, not by membership. Every delete must be scoped to
  the team's environments, or it wipes that person's grants in other organizations.
- A relation constrained on `$this->attribute` matches nothing when it is eager-loaded:
  Eloquent builds the constraint from a fresh instance. Compare columns instead, or never
  eager-load it.
- `required_with:field` names an absolute key in the payload and `laravel-data` does not
  rewrite it for nested Data objects: build the key from the `ValidationContext`.
- `dontFlash` goes through `Arr::except`, which ignores `*` wildcards: exclude the parent
  key.
- Larastan types `__()` as a benevolent `array|string` that stops being benevolent as soon as
  `null` joins the union: extract a method with a `: string` return type.
- The environment-visibility filter lives only in `App\Monitoring\VisibleEnvironments`.
  `ofTeam()` is the one unfiltered query, and it serves only the Applications pages of
  someone who may manage applications — visibility is not a permission.

**Front end**

- `npx shadcn-vue add` generates Lucide icons. Replace them with Phosphor before committing.
- `npm run build` runs `typescript:transform` but not `vue-tsc`, so a PHP prop renamed
  without updating its Vue usage breaks `composer ci:check`, not the Docker build.
- `tests/Feature/TranslationsTest.php` only sees literal `$t()` and `__()` call sites. A
  string kept in a const table — a lookup keyed by an enum value, for instance — is
  unguarded.
- Tabular numbers are opt-in through `nc-num` and are never set on `body`: Inter's `tnum`
  also widens the hyphen, so "worker-batch" would read "worker - batch" everywhere.
- The bundled `@fontsource-variable/inter` subsets carry none of Inter's disambiguation
  features (zero, ss01, cv05, cv08), so the `font-feature-settings` on code-like text is
  inert until an unsubsetted Inter is bundled.

**Secrets**

- Laravel's HTTP client events carry the `Authorization` header and the URL. Never add a
  listener, a debugging package or an error tracker that records them without redaction.
- An unexpected exception inside the Horizon reader is reported by class, file and line only,
  never chained: its message may hold a URL with credentials or a slice of a response body.

## Pull requests

- One topic per pull request, with `composer ci:check` green.
- Say what changed and why in the description; the code stays free of prose comments.
- Update `CHANGELOG.md` under `Unreleased` when the change is visible to an operator.
- New user-facing strings need their Italian translation in `lang/it.json`.
