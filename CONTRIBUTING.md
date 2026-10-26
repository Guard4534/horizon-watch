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

That creates a demo organization with a day of readings and an administrator,
`admin@example.com` / `password`. The demo environments collect, but their hosts are on the
reserved example domains (`example.com`, `example.net`, `example.org`), so the poller never
reads them and no new reading arrives.

## Commands

```bash
./vendor/bin/sail composer ci:check      # everything CI runs
./vendor/bin/sail composer lint          # Pint, writing
./vendor/bin/sail composer test          # Pest only
./vendor/bin/sail composer test:coverage # Pest with the coverage floor CI applies
./vendor/bin/sail npm run check          # front-end lint and formatting
./vendor/bin/sail npm run types:check    # regenerates TS types, then vue-tsc
./vendor/bin/sail npm run test           # front-end unit tests (Vitest through vp test)
./vendor/bin/sail npm run build
./bin/smoke-prod.sh fresh                # builds the production image, checks a fresh install
./bin/smoke-prod.sh upgrade              # installs the last release, then upgrades it to this build
./bin/release-notes.sh v1.2.0            # prints the CHANGELOG.md section of a release
```

`composer ci:check` is the single entry point: Pint, Larastan, the front-end lint, the
front-end type check, the front-end unit tests and the Pest suite, in that order. Run it before
opening a pull request. CI also runs the Pest suite with coverage in a job of its own and fails
below the floor set in `composer test:coverage`, and builds the production image to run both
smoke tests.

The fresh smoke test brings up `compose.prod.yaml` on an empty volume, checks the redirects to
`/setup`, the services, the headers and the persisted key, then creates an account and an
environment and waits for the scheduler and a worker to store a successful reading. The
environment points at a `horizon` service (`docker/smoke/horizon.yaml`), an nginx that answers
Horizon's API with the JSON of `tests/Fixtures/Horizon`, so the reading goes through the HTTP
client, the address guard and the parsing of the production image. The upgrade smoke test
starts the newest earlier release (the latest `v*` tag that does not contain `HEAD`, with that
tag's `compose.prod.yaml` and image), creates the same data, switches to the new image and
checks that no migration is pending, the key and the data survived, the basic-auth password
still decrypts and readings go on. It needs the tags: `git fetch --tags`, or set
`HORIZON_WATCH_PREVIOUS` to the tag to start from.

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

## Translations

- English strings are the keys, so `lang/en.json` stays empty. The Italian lives in
  `lang/it.json`, and validation messages and field names in `lang/it/validation.php`.
- Counts go through `$tChoice()` or `trans_choice()` with a `singular|plural` key, even when
  the two Italian forms are the same.
- In Italian, Horizon's own words stay in English: job, queue, worker, master, supervisor,
  pending. The rest is translated: failed (falliti), node (nodo), waiting (in attesa),
  organization (organizzazione), application (applicativo), environment (ambiente). In both
  languages the tenant is an organization, never a team.
- Strings that only Laravel or Fortify print (the mail layout, the password reset email, the
  two-factor and password confirmation errors) have no call site here. They are listed in
  `frameworkTranslationKeys()` in `tests/Feature/TranslationsTest.php`; after upgrading
  either package, check that their wording has not changed.
- Adding a language takes a case in `App\Enums\Locale`, its name in `LocaleSwitch.vue`
  (`vue-tsc` asks for it), a `lang/<code>.json` and a `lang/<code>/` folder modelled on the
  Italian ones, and the code in the `APP_LOCALE` row of the README.

### Glossary

One concept, one word, in both languages. New strings and the documentation use these terms; a
string that needs another word for one of these concepts is a bug.

| English                       | Italian                                           | Rule                                                                                                                                 |
| ----------------------------- | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Owner, Admin, Member, Viewer  | Proprietario, Amministratore, Membro, Osservatore | The four organization roles, printed from `TeamRole::label()`, never as the raw value. Admin names only the organization role.       |
| instance admin                | amministratore dell’istanza                       | The panel-wide role of the account created at `/setup`; never shortened to admin.                                                    |
| Horizon URL                   | URL Horizon                                       | The Horizon dashboard address as opened in the browser, the only address an environment has; never domain, host or path.             |
| Type                          | Tipo                                              | The environment type: Production, Pre-production, Staging, Development, Testing, Demo. It sets the colour and what production means. |
| healthy                       | regolare                                          | Status `active`, and nothing else.                                                                                                   |
| needs attention               | da gestire                                        | Every evaluated state that is not healthy: the filter, the KPI, the badge, the empty states. A paused collection is neither.         |
| alert, opened, resolved       | alert, aperto, risolto                            | The object and what happens to it; never anomaly or incident. The list is Open alerts / Alert aperti.                                |
| Mark as handled, Handled by … | Segna come gestito, Gestito da …                  | The action, and the state it leaves.                                                                                                 |
| Mute                          | Silenzia                                          | Only the per-alert action.                                                                                                           |
| maintenance, Maintenance for… | manutenzione, Manutenzione per…                   | Whatever creates a maintenance window.                                                                                               |
| Quiet hours                   | Fascia di quiete                                  | The daily hours, in the organization time zone, when only criticals go out.                                                          |
| Extra addresses               | Indirizzi aggiuntivi                              | The admin list of email addresses beyond the members.                                                                                |
| destination                   | destinazione                                      | Any place a notification goes: an address or the webhook.                                                                            |
| signing secret                | segreto di firma                                  | The key of the webhook signature, always by this name.                                                                               |
| webhook URL                   | URL del webhook                                   | The address the organization's webhook deliveries go to; never webhook address.                                                      |
| Password needed               | Password da inserire                              | The mark of an environment with a basic-auth user and no stored password, as an import leaves it; its collection stays paused.       |
| Log in, Log out               | Accedi, Esci                                      | The login and logout actions, in every label and text.                                                                               |

Delivery errors are sentences (`deliveryErrorLabel` in `resources/js/lib/alerts.ts`), never the
codes of `App\Enums\DeliveryError`.

## Tests

Tests run on PostgreSQL, against the `testing` database. To run a suite in parallel with
another one, point it at a database of its own:

```bash
./vendor/bin/sail exec pgsql createdb -U sail testing_two
docker compose exec -u sail -e DB_DATABASE=testing_two laravel.test php artisan test
```

Front-end unit tests sit next to the module they test, as `*.test.ts`, and import from
`vite-plus/test`. They cover pure logic in `resources/js/lib`, the helpers next to components
and the composables; nothing is mounted. They run in the `Europe/Rome` time zone (set in
`vite.config.ts`), so date arithmetic meets a daylight-saving change.

Monitoring tests build the few applications and environments they need with factories and
`Tests\Support\Readings::record()`. The demo organization of `DatabaseSeeder` is pinned only by
`tests/Feature/Seeders/DatabaseSeederTest.php`.

`tests/Feature/Horizon/LiveHorizonTest.php` is skipped unless `HORIZON_WATCH_LIVE_URL` points
at a real Horizon dashboard (`HORIZON_WATCH_LIVE_USERNAME` and `HORIZON_WATCH_LIVE_PASSWORD` for
basic auth). CI does not run it: that would mean keeping a second Laravel application with
Horizon and Redis in this repository, with its own dependencies to upgrade, for one test. Run it
by hand before a release against a Horizon prepared to exercise what the fixtures cannot:

- fail more than 50 jobs within the hour, then set `HORIZON_WATCH_LIVE_EXPECT_FAILED` to how
  many failed, so the test checks that the 50-per-page paging of failed jobs reaches them all;
- keep one long job running and set `HORIZON_WATCH_LIVE_EXPECT_RESERVED=1`, so it checks that
  a reserved job and its start time are read. The job has to outlive signals:
  `php artisan horizon:pause` sends `SIGUSR2`, which ends a plain `sleep(600)` early, so loop
  with `$end = time() + 600; while (($left = $end - time()) > 0) { sleep($left); }`;
- run it once more after `php artisan horizon:pause` with `HORIZON_WATCH_LIVE_EXPECT_PAUSED=1`.

Without those variables it still checks that the status, the masters, the failed and pending
lists and the queue runtimes of the busiest queues are read.

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
- `tests/Feature/TranslationsTest.php` only sees literal translation calls and literal
  `title`, `kicker`, `subtitle`, `description` and `buttonText` properties. A string kept in
  a const table — a lookup keyed by an enum value, for instance — is unguarded. The test also
  fails on an Italian entry that nothing uses any more, and on a translation that drops a
  `:placeholder` or a plural form of its key.
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
- Update `CHANGELOG.md` under `Unreleased` when the change is visible to an operator, with an
  **Upgrade note** when an operator has to do something.
- New user-facing strings need their Italian translation in `lang/it.json`.
- Never edit a migration that has been released: add a new one.

## Compatibility

Horizon Watch follows Semantic Versioning. Within a major version, these keep working as the
README documents them, and a change that breaks one of them waits for the next major version:

- the environment variables and their defaults, and the services of `compose.prod.yaml`;
- the `artisan` commands the README documents, and the roles of the image's entrypoint;
- `GET /health` and `/up`;
- version 1 of the webhook payload, its headers and its signature: fields may be added, never
  removed, renamed or given another meaning;
- the upgrade path: every release migrates a database of any earlier release of the same major
  version, and the upgrade smoke test proves it from the previous one.

## Releasing

1. Move the `Unreleased` entries of `CHANGELOG.md` under a new `## [x.y.z] - YYYY-MM-DD`
   heading, start an empty `Unreleased` section and update the links at the bottom.
2. Run the live Horizon test by hand (see [Tests](#tests)), then merge to `main`.
3. Tag the merge commit `vx.y.z` and push the tag. A tag with a suffix, such as `v1.1.0-rc.1`, is
   a pre-release.

The version the panel reports is the tag without its `v`: for the tag `v1.0.0` the release
workflow builds the image with `VERSION=1.0.0`, which the sidebar, `artisan about`,
`GET /health` and the `User-Agent` show. `Version::current()` drops a leading `v` from a
hand-built `VERSION` too.

The release workflow then runs every CI job on the tag, including both smoke tests, and stops
if one fails or if a stable tag has no `CHANGELOG.md` section (a pre-release falls back to
`Unreleased`). Only then does it push the image as `x.y.z` and, for a stable release, also as
`x.y`, `x` and `latest`, and publish the GitHub release with that section as its notes and a
`compose.prod.yaml` pinned to `x.y.z`. Check that the release page, the image tags and the
pinned compose file are there.
