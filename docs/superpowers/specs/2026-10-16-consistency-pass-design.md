# Consistency pass before 1.0.0: design

Date: 2026-10-16. Branch: `release/1.0`. Status: draft for owner review.

## Why

Doing a fresh production install, the owner found the same things asked, named and shown in
different shapes. The first examples: an application asks for a domain, an environment for a URL,
sometimes for the full URL with `/horizon`; and a "production" flag next to a colour that already
has a `prod` choice. Owner rule: **inconsistencies and useless redundancies are removed**, so a
team that installs Horizon Watch from GitHub meets one shape for each concept.

Two read-only multi-agent sweeps (inconsistencies: 80 verified findings; redundancies: 31
verified findings) were deduplicated into the themes below. Each theme states the one rule that
wins and the changes that follow from it. File references are starting points, not an exhaustive
list; the implementation plan resolves each site.

## Owner decisions (2026-10-16)

| Topic                  | Decision                                                                                                                                                        |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Production vs colour   | One field, **environment type**. The colour follows the type. "Production" means type `production`. The `is_production` flag and the `worker` choice disappear. |
| Application domain     | **Removed.** The only address is each environment's full Horizon dashboard URL.                                                                                 |
| Duration notation      | **Spaced**: `45 s`, `17 min`, `1 h 5 min`, `2 d 3 h`, everywhere (panel, emails, webhook `summary`). Compact form only on chart axes.                           |
| Is `degraded` healthy? | **No.** Healthy means status `active`; everything else with a status needs attention.                                                                           |

Defaults chosen by the implementer (the owner may override them in review):

| Topic                                                   | Default                                                                                                                             |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Italian role names                                      | Translate all four: Proprietario, Amministratore, Membro, Osservatore. Record the rule in the CONTRIBUTING glossary.                |
| Time zones                                              | 1.0 labels zones where times are entered or shown; moving the zone to organization settings is deferred.                            |
| Release version                                         | Bare `1.0.0` in the app, `/health` and the User-Agent; git tags stay `v*`.                                                          |
| Internal "anomaly" identifiers                          | Renamed to "alert" now (permission value, policy ability, component and config names), with a config fallback for `wall_anomalies`. |
| Alert-rule scopes                                       | Stay keyed by environment name (unchanged).                                                                                         |
| Alert text authorship                                   | Client renders, with the shared keys and formatters of theme 6.                                                                     |
| `environments.last_polled_at`, `ManageCredentials` gate | Left as they are for 1.0.                                                                                                           |
| Action rows                                             | Right-aligned, secondary then primary.                                                                                              |

## Constraints

- Migrations already shipped in v0.1.0 are never edited; every schema change is a new migration
  with a working `down()` where data allows.
- The webhook v1 payload keeps its keys and types. Only the free-text `summary` changes wording
  (durations), noted in the CHANGELOG.
- Every changed English source string renames its `lang/it.json` key in the same commit;
  `TranslationsTest` must stay green (no unused, no missing keys).
- `composer ci:check` green after every batch, run in the Sail container.
- Status colours stay reserved; identity colours stay in the cool range (`PRODUCT.md`).

## Themes

### 1. Where Horizon lives (owner item a)

**Rule.** An environment's full Horizon dashboard URL, as opened in the browser, is the only
address the product asks for, stores and calls. One label, "Horizon URL", one hint, one
normaliser, one outbound-URL rule.

- **Drop `applications.host`** (new migration). Remove it from `Application`,
  `ApplicationFormData`, `ApplicationWizardData`, `HostWithoutSchemeOrCredentials` (delete the
  rule), `ApplicationData`, queries, factories, seeder and tests.
- Where a domain was displayed (application list, application page, wizard step 3,
  environment create subtitle), show a **derived domain**: the host of the application's
  production-type environment URL, else of its first environment. Application search matches
  the name and the environments' URL hosts.
- **Wizard:**
    - Step 1 asks only the application name; the "Horizon path" field is removed.
    - Step 2: the first environment row asks the full Horizon URL.
    - Further rows get a suggested URL derived from the first row's URL. Take the host and path; a
      non-production row gets `<name>.<base host>`, where the base host is the first row's host
      with a leading `<first row name>.` label removed. Rows stop following once edited by hand.
- **Add environment:** prefilled with the same suggestion, built from the application's
  production environment URL (else its first environment). The form stops following once the
  user edits it.
- **Normalisation on the server** (`EnvironmentFormData`):
    - trim the URL;
    - lowercase scheme and host;
    - strip trailing slashes and a trailing `/api…` suffix (the same suffixes
      `HorizonTarget::suggestedDashboardUrl` knows);
    - keep the rejection of credentials, query strings and fragments.
- **Network guard at save:** extract an `OutboundUrl` rule (scheme, no credentials, no query, an
  IP-literal guard check, no DNS at validation time). Use it in `horizonUrlRules()` and in
  `WebhookUrl`, with the message "This address points to a network the panel does not call."
  Update the README, which already claims this check.
- **Display-only re-derivation:** `environments/Show.vue` `mastersPath` re-derives the API path.
  Remove the hint or send the path from the server.
- **Labels:**
    - "Horizon URL" everywhere, including rule messages.
    - Placeholder `https://shop.example.com/horizon`.
    - One hint: "The address of the Horizon dashboard, as you open it in the browser."

### 2. Environment type replaces colour and production flag (owner item b)

**Rule.** Each environment has one **type**. The type decides the identity colour and whether the
environment is production. Visibility "everything except production" means "type is not
production".

- Enum `EnvironmentType`: `production`, `preprod`, `staging`, `development`, `testing`, `demo`.
    - Translated labels: Production / Produzione, Pre-production / Pre-produzione, Staging /
      Staging, Development / Sviluppo, Testing / Test, Demo / Demo.
    - It replaces `EnvironmentColor`; the old `worker` and `develop` values go.
- **Colours per type**, cool range, renaming the existing `--env-*` tokens:
    - production → rose-violet;
    - preprod → violet;
    - staging → indigo;
    - development → blue;
    - testing → slate;
    - demo → cyan.
- **Migration** (new):
    - Add `environments.type`.
    - Fill it:
        - `is_production = true` → `production`, whatever the colour, so everything hidden today
          stays hidden;
        - otherwise by colour: `prod` → `production`, `preprod` → `preprod`, `staging` → `staging`,
          `develop` → `development`, `demo` → `demo`, `testing` → `testing`, `worker` → `staging`.
    - Drop `color` and `is_production`.
    - The same mapping, without the flag, applies to `alerts.environment_color`, renamed to
      `alerts.environment_type`. Old alert rows must still cast.
    - `down()` restores `color` from `type` and `is_production = (type = production)`.
- **Upgrade note (CHANGELOG):**
    - Environments coloured `prod` but unflagged become production, so members limited to
      "everything except production" no longer see them.
    - Non-production `worker` environments become `staging`.
- **Name-based suggestion:** `looksLikeProduction` becomes a name → type suggestion map shared by
  the wizard and Add environment, gated by a `typeEdited` flag. A new environment never defaults
  to `production` unless its name suggests it. Delete `productionEdited`, the production checkbox
  and its copy-paste tooltip bug.
- **Consumers:**
    - `VisibleEnvironments` (NonProduction filter);
    - `EnvironmentData`, `EnvironmentSummaryData`, `EnvironmentOptionData`, `AlertData`,
      `MaintenanceWindowData`, `MaintenanceTargetData`, `RuleScopeData`;
    - `EnvPill` (shield when type is production), `ColorPicker` → `TypePicker`;
    - `WallFilters` (chips by type, ordered by the enum, not by a hard-coded name list);
    - seeder, factories, Members pickers.
- **Webhook:** unchanged (the payload carries neither colour nor flag).

### 3. Environment state: one evaluated state, one label, one tone

**Rule.** Display state precedence, applied by one helper set in `readings.ts`:
not visible > collection paused > reading error > stale > no reading yet > Horizon status.
Each state has one wording and one tone. Status translation keys are never shared with other
concepts.

- **Italian:** `unreachable` → "non raggiungibile"; delivery errors get their own sentence keys
  (theme 13).
- **Paused collection:**
    - It is a state of its own on every surface: tile, mobile row, application lists, header and
      lamp tooltip.
    - It is excluded from the healthy and needs-attention counts, server side in `WallQuery` and
      the application list query.
    - It is not persisted to `environment_states`.
- **One reading-error label helper** (`readingErrorLabel`). Delete the dead
  `ReadingError::label()` and the three Vue `v-if` chains.
- **Horizon "paused" tone:** `horizonStatusTone` delegates to `statusColor`, so it is the same in
  the pill, banner and Master tile. Environment-level chips say "Horizon paused".
- **Inactive banner:** headline "Horizon inactive", with "No active master supervisor" as the
  explanation.

### 4. Wall KPIs and "needs attention"

**Rule.** KPIs are computed once and mean the same on desktop and mobile; mobile may drop tiles,
never redefine them. Healthy = `active`. Every other evaluated state needs attention: one term,
"needs attention" / "da gestire", for the filter, the KPI, the per-application badge and the
empty states. Open alerts are "Open alerts".

- One KPI composable for both layouts: "Healthy N / M", "Open alerts", "Pending", "Failed".
- Remove the mobile "Issues" tile, which repeated the Problems tab.
- Remove the duplicated `environmentsUp`/`environmentsActive` field.
- One `AttentionBadge` (`nc-tag-sm`, toned by the worst status) in the wall groups, the
  application list and the mobile list, with the same count rule everywhere
  (watched, has status, not healthy).
- Labels: "Throughput" with the unit on the value (`12/min`) on desktop and mobile.

### 5. Show the number the product judges

**Rule.** A figure is coloured only by its own rule, and the number a rule, email or webhook talks
about appears on screen. Tones come from the rule severity: warning is amber, critical is red.
There are no client-only colour ladders.

- **Failed jobs:**
    - "Failed · last hour" (`failedLastHour`, "at least" when capped) is the toned figure on the
      environment page, the mobile views and the application cards.
    - Horizon's own window count becomes a neutral note.
    - On the wall tile, the last-hour figure is toned and the window count stays neutral.
- **Thresholds:** they are sent with their rule severity (`thresholdsOf`). The tone is the
  severity colour when breached. Drop the 300 s escalation in `waitColor`.
- **Per-queue Status column:** replaced by contributor flags (wait over threshold, no worker)
  instead of re-applying environment rules.

### 6. Durations, numbers and times: one formatter per kind

**Rule.**

- Durations use one floor-based formatter in TypeScript and PHP: at most two units, a plain
  space, and translatable short units (`45 s`, `1 min 30 s`, `1 h 5 min`, `2 d 3 h`). Days start
  from 24 h.
- The compact form is for chart axes only.
- Relative times ("… ago") are built on the same helper.
- Live counts use `formatCount`. Thresholds and alert values use exact, locale-grouped numbers,
  the same in TypeScript (`Intl.NumberFormat`) and PHP (`Number::format`).
- Instants use one formatter that adds weekday, day and month when not today.

Changes:

- Fold `formatWait`, `monitoring.formatDuration`, `readings.formatAge` and `formatElapsed` into
  `formatSeconds` and `formatAgo`.
- Rename the rule phrase helper `alertRules.formatDuration` → `formatRuleFor`.
- PHP mirror: `AlertMail::duration`/headline. The webhook `summary` wording changes (CHANGELOG).
- Delete `formatMutedUntil`; `formatInstant` lives in `lib/datetime.ts`, used for muted-until
  and the maintenance badge alike.
- One unit fragment for failed-window labels, and one `quietHoursSummary(from, to, tz)`.
- `formatThreshold` loses its `s` special case: thresholds keep their input unit, `> 60 s`.

### 7. Alert vocabulary

**Rule.** The object is an **alert** everywhere. It is **opened** and **resolved**. The action is
**Mark as handled**; "Handled by :name" is the state.

- Copy:
    - "Open alerts";
    - "No open alerts: every environment you can see is within its thresholds.";
    - the banner says "opened";
    - the email says "Opened on :time (:timezone)".
- Identifiers:
    - `HandleAnomaly` → `HandleAlert` and `anomaly:handle` → `alert:handle` (not persisted, so no
      migration);
    - `AnomalyList` → `OpenAlertList`, `openAnomalies` → `openAlerts`;
    - config `wall_anomalies` → `wall_alerts`, with a fallback.
- **One `alertTone` / `alertIcon` helper** (resolved → ok and a check icon; muted → neutral;
  otherwise severity and the rule icon) for every alert list. Environment status never colours an
  alert row. AlertMarks always renders.
- **Metric facts from the server:**
    - `AlertRuleData` and `AlertData` carry the comparator and `isStateRule`;
    - delete `STATE_METRICS` and `comparison()` from TypeScript.

### 8. Suppression vocabulary

**Rule.**

- "Mute" / "Silenzia" is only the per-alert action.
- Anything that creates a maintenance window says "maintenance" / "manutenzione".
- Quiet hours are "Quiet hours" / "Fascia di quiete".

Changes:

- The "Silence for…" quick action → "Maintenance for…" / "Manutenzione per…", and its toast says
  "Maintenance window started."
- Italian "Finestra di silenzio" → "Fascia di quiete" in every key.

### 9. Environment page: say each thing once

**Rule.**

- The banner explains the environment's state, with headline, cause and consequence.
- The alerts box lists the alerts, each with its rule and age.
- The freshness line gives only the age of the last reading.

Changes:

- Remove `incidentAlert` and the banner's "started" and "Rule:" lines. The banner could show an
  unrelated alert's rule.
- `ReadingFreshness` hides the error reason while the banner is shown.
- Keep the per-section "last known · X ago" notes and the Master tile note: they are not
  redundant.
- Optional copy trim: drop the banner's generic "last known" clauses.

### 10. Application page and entry points

- **One list of environments:** keep `EnvironmentCard` and add Failed (last hour), Workers and
  Throughput. Delete `EnvironmentComparison`, the "Environments" count tile, and the label
  `v-if` chain; build the stats with `trans()` in the computed.
- **Landing pages:**
    - After creating an application or an environment, the user lands on the application page.
    - The edit pages' Cancel and breadcrumb go back to it.
    - Remove the duplicate "Add environment" on application Edit.
    - The wall's "Add environment" button that opened the list becomes "Go to applications".
- **Editing an environment:** an "Edit environment" action on the environment page, desktop and
  mobile, for managers. The pencil means edit everywhere; the gear means settings only.

### 11. One environment form

**Rule.** The wizard row and the Add/Edit page are one component with one behaviour; compact
mode may shorten labels only. Limits and defaults come from server config.

- **Basic auth:** an explicit toggle everywhere. On Edit it starts on when a user or password is
  stored; turning it off removes the credentials, and the form says so.
- **Name hint:**
    - Create: "Lowercase letters, digits and dashes."
    - Edit: "Links keep working; alert overrides follow the new name."
    - Slugs never change.
- **Labels:** "Collection interval" / "Intervallo di raccolta", and "Collect readings" as a
  switch with its own hint.
- **Poll interval limits:** sent as `PollIntervalData {default, min, max, step}` instead of
  numbers hard-coded in Vue.
- **Connection test:** on Add environment it runs once on URL blur (debounced, within the
  existing throttle), as in the wizard.

### 12. Forms, actions, destructive operations, errors

**Rule.**

- Every form is a real `<form @submit.prevent>`, so Enter submits.
- One action row: right-aligned, secondary then primary.
- Edit pages enable Save only when the form is dirty.
- "Cancel" leaves without saving; an in-place revert is "Discard changes".
- Destructive triggers carry the danger tone (`DangerZoneCard` on settings and edit pages, a red
  icon with a tooltip in dense rows), with friction by tier:
    - confirm by name for cascading deletes;
    - a simple confirm for people and bulk changes;
    - one click for cheap records.
- Field errors use `InputError`; form-level errors use one `FormErrors` (`role=alert`).

### 13. Notifications and delivery

- **Test buttons:** the email and webhook tests live only in Alert settings. Delete `DeliveryTest`
  from the Alerts page and move its footnote under the buttons.
- **Non-managers** see the read-only `DeliveryPolicy` instead of a second grid. The "Email is not
  set up" notice moves into `DeliveryPolicy`. Hide the target lines while `AudienceWarning` says
  nobody is reached.
- **Emails:** opened and repeated emails no longer print the value twice. The headline carries
  it; the "Value" row stays on the resolved email.
- **Vocabulary:**
    - "Extra addresses" for the admin list;
    - "destination" for any place a notification goes;
    - "signing secret" always.
- Delivery errors use sentence keys, never enum codes.

### 14. People, roles, visibility

- **Role and visibility labels:** served from `TeamRole::label()` and `MemberVisibility::label()`
  as `{value, label}` options. Delete the client copies in `lib/members.ts`; no raw role key is
  ever printed.
- **Two-factor lockout:** the message names "whoever runs this panel" / "chi gestisce questo
  pannello".
- **"Admin"** means only the organization role; the `/setup` account is the "instance admin".
- **Visibility wording:** "environments you can see" / "Outside your visibility", replacing
  "Not on your wall".
- **Members page:** the "How access works" card is folded into the permission matrix footnote,
  and the page stacks on phones.
- **Leaving an organization** is one action, "Leave the organization", with one modal that warns
  the last admin.

### 15. Shared primitives

- **Status** is a circle: `StatusLamp` in dense rows, `StatusPill` in headers. Both accept null
  plus a label. Inline dots and ad-hoc pills go.
- **Identity** is `EnvPill`, with the shield for production type, wherever an environment is
  named. The hierarchy separator is `/`.
- **Metric cards:** one `MetricCard` with sizes `hero` / `default` / `compact`. It replaces
  `KpiCard`, `MetricTile` and the inline tiles.
- **Booleans:**
    - `nc-switch` for on/off state;
    - a new `nc-checkbox` for options and multi-select picks;
    - `nc-radio` for real radios only.
- **Layout:**
    - a shared `.nc-two-col` that stacks below 1024 px;
    - `.nc-page` and `.nc-page-narrow`;
    - a `PageHeader` on monitoring detail and form pages.
- **Settings pages:** remove the in-page "Settings" h2 that repeats the header, and the duplicate
  heading on Organizations.
- **Connection test result:** one tone, icon and label helper, so a failure is red in the row
  header and in the line below.

### 16. Account and authentication

- **Password policy:** one server-side description built next to `Password::defaults()`, i.e.
  `passwordrules` plus "At least :min characters, with letters and numbers". It is shown as
  helper text on Setup, Invitation, Reset (the Fortify reset view gets the props) and Security.
- **Labels:**
    - "Log in" / "Log out" everywhere;
    - "Name" and "Email" as field labels, with placeholder `email@example.com`.

### 17. Data and payload trimming (new migrations)

- **Drop `alerts.notified`.** It always equals `last_notified_at IS NOT NULL`: use
  `Alert::wasNotified()` and guard with an update before the drop.
- **Drop `alerts.unit`.** It always equals `metric->unit()`: an accessor keeps the webhook `unit`.
- **Drop `users.email_verified_at`.** Email verification is not a feature, and the column stayed
  set after an email change.
- **Page props:**
    - `auth.user` carries only name and email; locale and two-factor state already have their own
      props;
    - remove props sent but never read (`colors` on application Edit, `teams` on
      Organizations).
- **Config:** `failed_rate_minutes` and `poll_tick_seconds` become constants, because only one
  value is safe for each.
- **Severity order:** comes from the server (`worstStatus`). Delete the TypeScript copy.
- **Routes:** merge the two adjacent `auth` route groups in `routes/settings.php`.

### 18. Time zones (labelling only)

- **Organization time zone:** the hint says "Quiet hours and alert emails use this time zone".
- **Maintenance:** the list, badge and form show the browser zone they use.
- **`.env.example`:** add `HORIZON_WATCH_DEFAULT_TIMEZONE`.

### 19. Documentation and version

- **README:**
    - the repeat policy, including "first detection only";
    - the Horizon URL check at save;
    - "Maintenance for…";
    - the environment type;
    - no application domain;
    - the version examples.
- **Other docs:** SECURITY.md (secret rotation on a host move), CHANGELOG (upgrade notes for
  theme 2 and the dropped columns), and the CONTRIBUTING glossary (roles, terms of themes 4,
  7, 8 and 13).
- **Version:** bare `1.0.0` in `Version::current()` and the release workflow build arg. Update
  the test fixtures that expect `v`.

### 20. Configuration export and import (owner request, 2026-10-16)

**Why.** Move an organization's monitoring setup between installations and versions (a new
server, a fresh install after a major upgrade, a staging panel) without retyping it. Secrets never
leave the panel.

**Owner decisions.** Content: applications, environments and alert rules. Both panel and command
line. Import shows a preview and lets the admin choose to update or skip existing items; nothing
is ever deleted. Environments that need a basic-auth password arrive with collection paused and a
"password needed" mark.

**File.** One JSON document per organization, UTF-8, at most 1 MB:

```json
{
    "format": "horizon-watch.config",
    "version": 1,
    "exported_at": "2026-10-16T09:00:00Z",
    "app_version": "1.0.0",
    "organization": "Example Organization",
    "applications": [
        {
            "name": "Acme Shop",
            "environments": [
                {
                    "name": "production",
                    "type": "production",
                    "horizon_url": "https://shop.example.com/horizon",
                    "basic_auth_user": "ops",
                    "poll_interval_seconds": 60,
                    "polling_enabled": true
                }
            ]
        }
    ],
    "alert_rules": [
        {
            "scope": "organization",
            "metric": "queue.pending",
            "threshold": 2000,
            "severity": "warning",
            "notify_by_email": true,
            "enabled": true,
            "for_minutes": 0
        },
        { "scope": "production", "metric": "queue.max_wait", "threshold": 90 }
    ]
}
```

- Never exported: basic-auth passwords, the webhook signing secret, credentials inside URLs
  (stripped), notification settings, maintenance windows, members, invitations, readings, alerts.
- Override rows carry only their non-null fields; organization rows carry every field.
- `version` is the file format version. The importer upgrades older versions step by step and
  refuses a newer version with "This file comes from a newer Horizon Watch: update the panel
  first."

**Matching.** Applications by name (case-insensitive) within the organization; environments by
name within their application; alert rules by (scope, metric).

**Export.**

- Panel: an "Export" action on the Applications page downloads
  `horizon-watch-<organization-slug>-<YYYY-MM-DD>.json`.
- CLI: `artisan applications:export <organization-slug> [--output=<path>]`, writing to stdout by
  default.
- Only users who can manage applications and alert rules (admins, owners) may export.

**Import.**

- Panel: an "Import" action on the Applications page opens an import page. Uploading the file
  shows a preview with one row per application, environment and rule, each marked:
    - **new**;
    - **changed**, with the fields that differ;
    - **unchanged**;
    - **invalid**, with the validation message.
- An "Update existing items" switch (off by default) decides whether changed items are applied
  or skipped.
- "Import" applies everything in one transaction and shows a summary toast: created, updated,
  skipped. An invalid item blocks the import until the file is fixed.
- Every item goes through the same validation as the forms: name format and uniqueness, the
  type enum, `HorizonUrl::normalize`, `OutboundUrl`, poll interval limits, rule threshold
  limits, the reserved scope name and the wizard environment cap. A hand-edited file can do no
  more than the forms can.
- CLI: `artisan applications:import <organization-slug> <path|-> [--update] [--dry-run]`.
  `--dry-run` prints the preview and changes nothing; `-` reads stdin, so
  `docker compose run --rm -T web artisan applications:import shop - < file.json` works on the
  production stack.
- Passwords:
    - A new environment with a `basic_auth_user`, or an existing one whose user changes, is saved
      with no password and `polling_enabled = false`.
    - The environment shows "Password needed" (derived: user set, password empty) on the
      application page, the environment page and the edit form until a password is entered.
    - An updated environment with the same user keeps its stored password.
- Never deleted: items missing from the file stay as they are.

**Tests.**

- Round trip: export, then import into an empty organization, then export again gives an equal
  document apart from `exported_at`.
- The preview classifies new, changed, unchanged and invalid items.
- Update vs skip.
- Passwords and secrets never appear in an export, including credentials pasted in legacy URLs.
- The password-needed pause on import.
- Malformed JSON, the wrong `format`, a newer `version`, a file over 1 MB, a disallowed URL.
- Permissions (viewer and member forbidden).
- The CLI dry-run prints the preview and changes nothing.
- Translations.

**Docs.** A README section "Moving the configuration to another panel", and a CHANGELOG entry.

## Out of scope

- Moving the time zone to organization settings.
- Rule scopes by type.
- `last_polled_at`.
- Merging `ManageCredentials`.
- Server-authored alert text.
- Chat channels.

## Testing

- **Each theme ships with:**
    - tests on the new rule;
    - updated assertions where wording or props change;
    - removed tests for removed code.
- **Migrations:**
    - a test that runs them on rows with every old colour and flag combination;
    - a test that alert rows with old colours still cast;
    - a test of `down()`.
- **Visibility:** a test that an environment flagged production but coloured `worker` stays hidden
  from non-production members after the migration.
- **Front-end:**
    - unit tests for `formatSeconds`, `formatAgo`, the URL suggestion and the type suggestion;
    - a browser pass (desktop and mobile) on the wall, environment page, application page, wizard,
      Add/Edit environment, alert settings and Members.
- **Release smoke:** `bin/smoke-prod.sh fresh` and `upgrade` (from v0.1.0) both pass.

## Delivery

Suggested batches, each its own commit on `release/1.0` with `ci:check` green; final order set
by the plan:

1. Environment type (theme 2) and address model (theme 1), the two schema changes.
2. State, KPIs and judged numbers (themes 3, 4, 5).
3. Formatters (theme 6) and the webhook `summary` wording.
4. Vocabulary (themes 7, 8, 13, 14, 16) with `lang/it.json`.
5. Pages and forms (themes 9, 10, 11, 12).
6. Primitives and layout (theme 15).
7. Data trimming (theme 17).
8. Docs, time zone labels and version (themes 18, 19).
9. Configuration export and import (theme 20).
