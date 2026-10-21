<?php

use Symfony\Component\Finder\Finder;

function pagesAndFormsSource(string $path): string
{
    return file_get_contents(resource_path('js/'.$path));
}

/**
 * @return array<string, string>
 */
function pagesAndFormsSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name(['*.vue', '*.ts'])->notName('*.test.ts')->exclude(['generated', 'routes', 'actions', 'wayfinder', 'components/ui']) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

function pagesAndFormsActionRow(string $source): string
{
    $start = strpos($source, 'class="nc-actions"');

    expect($start)->not->toBeFalse();

    return substr($source, $start, 1400);
}

test('the environment banner explains the state and leaves the alerts to the alerts box', function () {
    expect(pagesAndFormsSource('components/monitoring/environment/IncidentBanner.vue'))
        ->not->toContain("\$t('Rule:')")
        ->not->toContain(':elapsed')
        ->not->toContain('alert: App.Data.Monitoring.AlertData')
        ->not->toContain('nodes and queues are the last known')
        ->not->toContain('The figures are the last known.');

    expect(pagesAndFormsSource('composables/useEnvironmentDetail.ts'))->not->toContain('incidentAlert');

    foreach (['pages/monitoring/environments/Show.vue', 'components/mobile/environment/EnvironmentMobile.vue'] as $path) {
        expect(pagesAndFormsSource($path))
            ->not->toContain('incidentAlert')
            ->not->toContain(':alert=')
            ->toContain(':hide-reason="incident !== null"');
    }
});

test('the freshness line gives only the age while the banner explains the state', function () {
    expect(pagesAndFormsSource('components/monitoring/ReadingFreshness.vue'))
        ->toContain('hideReason?: boolean')
        ->toContain('stale && !hideReason')
        ->toContain('!hideReason && (readingError !== null || stale)');
});

test('managers reach the environment form from the environment page, on desktop and mobile', function () {
    foreach (['pages/monitoring/environments/Show.vue', 'components/mobile/environment/EnvironmentMobile.vue'] as $path) {
        expect(pagesAndFormsSource($path))
            ->toContain('const { canManageApplications } = useVisibility();')
            ->toContain('v-if="canManageApplications"')
            ->toContain('editEnvironment({')
            ->toContain('<PhPencilSimple');
    }
});

test('the gear means settings and the pencil means edit', function () {
    $gears = collect(pagesAndFormsSources())
        ->filter(fn (string $source) => str_contains($source, 'PhGearSix'))
        ->keys()
        ->values()
        ->all();

    expect($gears)->toBe(['components/UserMenuContent.vue']);
});

test('the application page lists its environments once, as cards', function () {
    expect(file_exists(resource_path('js/components/monitoring/applications/EnvironmentComparison.vue')))->toBeFalse();

    expect(pagesAndFormsSource('pages/monitoring/applications/Show.vue'))
        ->not->toContain('EnvironmentComparison')
        ->not->toContain('stat.label ===')
        ->not->toContain("label: 'Environments'")
        ->toContain("trans('Worst status')")
        ->toContain(':key="stat.key"')
        ->toContain("key: 'worstStatus'");

    expect(pagesAndFormsSource('components/monitoring/applications/EnvironmentCard.vue'))
        ->toContain('failedLastHourText(environment)')
        ->toContain("'jobs.failed_per_hour'")
        ->toContain('environment.workers')
        ->toContain('`${environment.jobsPerMinute}/min`')
        ->toContain('environment.nodeCount');
});

test('the wall sends an admin with an empty application to the applications, not to a form', function () {
    expect(pagesAndFormsSource('pages/monitoring/Wall.vue'))
        ->toContain("\$t('Go to applications')")
        ->not->toContain("\$t('Add environment')");
});

test('form-level errors, danger zones and action rows each have one primitive', function () {
    expect(pagesAndFormsSource('components/FormErrors.vue'))->toContain('role="alert"');
    expect(file_exists(resource_path('js/components/AlertError.vue')))->toBeFalse();
    expect(pagesAndFormsSource('components/DangerZoneCard.vue'))->toContain('<slot />')->toContain('var(--st-down)');
    expect(file_get_contents(resource_path('css/nocturne.css')))
        ->toMatch('/\.nc-actions \{[^}]*flex-wrap: wrap;[^}]*justify-content: flex-end;/');

    foreach (['components/TwoFactorRecoveryCodes.vue', 'components/TwoFactorSetupModal.vue'] as $path) {
        expect(pagesAndFormsSource($path))->toContain('<FormErrors')->not->toContain('AlertError');
    }
});

test('the environment form has one behaviour in the wizard and on the pages', function () {
    $form = pagesAndFormsSource('components/monitoring/applications/EnvironmentForm.vue');

    expect($form)
        ->toContain(':min="pollInterval.min"')
        ->toContain(':max="pollInterval.max"')
        ->toContain(':step="pollInterval.step"')
        ->not->toContain('min="15"')
        ->not->toContain('max="300"')
        ->toContain("\$t('Collection interval')")
        ->not->toContain("\$t('Poll interval')")
        ->not->toContain('nc-radio')
        ->toContain("\$t('Lowercase letters, digits and dashes.')")
        ->toMatch('/\$t\(\s*\'Links keep working; alert overrides follow the new name\.\'/')
        ->toContain("\$t('Saving removes the stored username and password.')")
        ->toContain("@blur=\"\$emit('urlBlur')\"")
        ->toContain('typeFollowsName?: boolean;')
        ->not->toContain('!compact && !errorOf(')
        ->not->toContain('<template v-if="!compact">');

    $attributes = trans('validation.attributes', [], 'it');

    expect($attributes['pollIntervalSeconds'])->toBe('intervallo di raccolta')
        ->and($attributes['environments.*.pollIntervalSeconds'])->toBe('intervallo di raccolta');

    expect(substr_count($form, '<AuthField />'))->toBe(2);
    expect(substr_count($form, 'class="nc-switch"'))->toBe(2);

    $hardCoded = collect(pagesAndFormsSources())
        ->filter(fn (string $source) => str_contains($source, 'pollIntervalSeconds: 15'))
        ->keys()
        ->all();

    expect($hardCoded)->toBe([]);
});

test('Add environment is a real form that tests the address once the field is left', function () {
    $create = pagesAndFormsSource('pages/monitoring/environments/Create.vue');

    expect($create)
        ->toContain('@submit.prevent="submit"')
        ->toContain('novalidate')
        ->toContain('@url-blur="onUrlBlur"')
        ->toContain('@url-input="onUrlInput"')
        ->toContain('type-follows-name')
        ->toContain('v-model:auth="auth"')
        ->toContain(':poll-interval="page.pollInterval"')
        ->toContain('pollIntervalSeconds: page.pollInterval.default')
        ->toContain('useBlurConnectionTest(')
        ->toContain('@run="noteTested"')
        ->not->toContain('@click="submit"');

    expect(pagesAndFormsSource('components/monitoring/applications/EnvironmentForm.vue'))
        ->toContain('@input="onUrlInput"')
        ->not->toContain('v-model="model.horizonUrl"');

    $row = pagesAndFormsActionRow($create);

    expect(strpos($row, 'nc-btn-secondary'))->not->toBeFalse()
        ->and(strpos($row, 'type="submit"'))->not->toBeFalse()
        ->and(strpos($row, 'nc-btn-secondary'))->toBeLessThan(strpos($row, 'nc-btn-primary'));
});

test('Edit environment saves only changes, says what turning basic auth off does, and fences the delete', function () {
    $edit = pagesAndFormsSource('pages/monitoring/environments/Edit.vue');

    expect($edit)
        ->toContain('@submit.prevent="submit"')
        ->toContain('novalidate')
        ->toContain(':disabled="!form.isDirty || form.processing"')
        ->toContain('const auth = ref(storedCredentials.value);')
        ->toContain(':stored-credentials="storedCredentials"')
        ->toMatch('/:stored-credentials="storedCredentials"\s+editing\s+\/>/')
        ->not->toContain('type-follows-name')
        ->toContain(':poll-interval="page.pollInterval"')
        ->toContain('<DangerZoneCard')
        ->not->toContain("\$t('Credentials & collection')")
        ->not->toContain("\$t('Open')")
        ->not->toContain('@click="submit"');

    $row = pagesAndFormsActionRow($edit);

    expect(strpos($row, 'nc-btn-secondary'))->toBeLessThan(strpos($row, 'nc-btn-primary'));
});

test('the wizard is one form: Enter advances, errors sit in one box, the row delete looks dangerous', function () {
    $wizard = pagesAndFormsSource('pages/monitoring/applications/Create.vue');

    expect($wizard)
        ->toContain('@submit.prevent="advance"')
        ->toContain('novalidate')
        ->toContain(':poll-interval="page.pollInterval"')
        ->toMatch('/useApplicationWizard\([^)]*page\.pollInterval/s')
        ->toContain('<FormErrors :errors="[errors.environments]" />')
        ->toContain('<FormErrors :errors="unplacedErrors" />')
        ->toContain('nc-btn nc-btn-ghost nc-t-xs nc-tone-down')
        ->not->toContain('@click="submit"')
        ->not->toContain('v-for="(message, index) in unplacedErrors"');

    $row = pagesAndFormsActionRow($wizard);

    expect(strpos($row, 'nc-btn-secondary'))->toBeLessThan(strpos($row, 'nc-btn-primary'))
        ->and(substr_count($row, 'type="submit"'))->toBe(2);
});

test('Edit application saves only changes and leads back to the application page', function () {
    $edit = pagesAndFormsSource('pages/monitoring/applications/Edit.vue');

    expect($edit)
        ->toContain('@submit.prevent="submit"')
        ->toContain(':disabled="!form.isDirty || form.processing"')
        ->toContain('<DangerZoneCard')
        ->not->toContain('createEnvironment')
        ->not->toContain("\$t('Add environment')")
        ->not->toContain("\$t('Open')")
        ->not->toContain('@click="submit"');

    $row = pagesAndFormsActionRow($edit);

    expect($row)->toMatch('/:href="\s*show\(\{\s*current_team: slug,\s*application: application,?\s*\}\)\s*"/')
        ->and(strpos($row, 'nc-btn-secondary'))->toBeLessThan(strpos($row, 'nc-btn-primary'));
});

test('field errors go through InputError and form-level errors through FormErrors', function () {
    $local = collect(pagesAndFormsSources())
        ->filter(fn (string $source) => str_contains($source, 'class="field-error"') || str_contains($source, '.field-error'))
        ->keys()
        ->all();

    expect($local)->toBe([]);

    foreach ([
        'components/monitoring/rules/RulesForm.vue',
        'components/monitoring/members/MemberTable.vue',
        'components/monitoring/rules/NotificationSettings.vue',
        'pages/monitoring/applications/Create.vue',
    ] as $path) {
        expect(pagesAndFormsSource($path))->toContain('<FormErrors');
    }
});

test('an in-place revert says Discard changes', function () {
    foreach (['components/monitoring/rules/RulesForm.vue', 'components/monitoring/rules/NotificationSettings.vue'] as $path) {
        $source = pagesAndFormsSource($path);
        $cancel = strpos($source, '@click="cancel"');

        expect($cancel)->not->toBeFalse()
            ->and(substr($source, $cancel, 200))->toContain("\$t('Discard changes')");
    }
});

test('destructive triggers carry the danger tone', function () {
    expect(pagesAndFormsSource('pages/teams/Index.vue'))
        ->toMatch('/class="nc-btn nc-btn-ghost nc-tone-down"\s+data-test="team-delete-button"\s+:title="\$t\(\'Delete the organization\'\)"/')
        ->toMatch('/class="nc-btn nc-btn-ghost nc-tone-down"\s+data-test="team-leave-button"\s+:title="\$t\(\'Leave the organization\'\)"/');

    expect(pagesAndFormsSource('components/monitoring/members/PendingInvitations.vue'))
        ->toMatch('/class="nc-btn nc-btn-ghost nc-t-xs nc-tone-down"\s+:disabled="busy === invitation.id"\s+@click="act\(invitation, revokeInvitation\)"/');

    expect(pagesAndFormsSource('components/monitoring/rules/MaintenanceWindows.vue'))
        ->toMatch("/:class=\"\s*maintenance\.active\s+\?\s+'nc-tone-soft'\s+:\s+'nc-tone-down'\s*\"/");

    expect(pagesAndFormsSource('components/monitoring/rules/RulesForm.vue'))
        ->toContain('class="nc-btn nc-btn-ghost nc-t-xs nc-tone-down ml-auto"')
        ->toMatch('/class="nc-btn nc-btn-danger"\s+:disabled="resetting"\s+@click="confirmReset"/');

    expect(pagesAndFormsSource('components/monitoring/rules/WebhookSecret.vue'))
        ->toMatch('/class="nc-btn nc-btn-ghost nc-t-xs nc-tone-down"\s+:disabled="regenerating"\s+@click="confirming = true"/')
        ->toMatch('/class="nc-btn nc-btn-danger"\s+:disabled="regenerating"\s+@click="regenerate"/');

    expect(pagesAndFormsSource('components/TwoFactorRecoveryCodes.vue'))
        ->toMatch('/class="nc-btn nc-btn-ghost nc-tone-down w-fit"\s+@click="confirmingRegeneration = true"/');

    foreach (['components/DeleteUser.vue', 'pages/monitoring/applications/Edit.vue', 'pages/monitoring/environments/Edit.vue'] as $path) {
        expect(pagesAndFormsSource($path))->toContain('<DangerZoneCard');
    }
});

test('every form submits from its own submit button, and edit pages save only changes', function () {
    $clickSubmits = collect(pagesAndFormsSources())
        ->filter(fn (string $source) => preg_match('/@click="(submit|save\w*)"/', $source) === 1)
        ->keys()
        ->all();

    expect($clickSubmits)->toBe([]);

    expect(pagesAndFormsSource('components/ConfirmByNameDialog.vue'))
        ->toContain('id="confirm-by-name-form"')
        ->toContain('form="confirm-by-name-form"');

    expect(pagesAndFormsSource('components/monitoring/members/MemberTable.vue'))
        ->toContain('id="manual-visibility-form"')
        ->toContain('form="manual-visibility-form"');

    expect(pagesAndFormsSource('pages/settings/Profile.vue'))
        ->toContain('set-defaults-on-success')
        ->toContain(':disabled="processing || !isDirty"');

    expect(pagesAndFormsSource('components/teams/RenameOrganizationModal.vue'))
        ->toContain('v-slot="{ errors, processing, isDirty }"')
        ->toContain(':disabled="processing || !isDirty"');

    foreach (['pages/settings/Profile.vue', 'pages/settings/Security.vue'] as $path) {
        expect(pagesAndFormsActionRow(pagesAndFormsSource($path)))->toContain('type="submit"');
    }
});

test('every button says what it is, so Enter in a field never presses the wrong one', function () {
    $openingTag = function (string $source, int $at): string {
        $quoted = false;

        for ($i = $at, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '"') {
                $quoted = ! $quoted;
            } elseif ($source[$i] === '>' && ! $quoted) {
                return substr($source, $at, $i - $at + 1);
            }
        }

        return substr($source, $at);
    };

    $untyped = [];

    foreach (pagesAndFormsSources() as $path => $source) {
        preg_match_all('/<button\b/', $source, $found, PREG_OFFSET_CAPTURE);

        foreach ($found[0] as [, $at]) {
            if (preg_match('/\s:?type="/', $openingTag($source, $at)) !== 1) {
                $untyped[] = $path;
            }
        }
    }

    expect(array_values(array_unique($untyped)))->toBe([]);
});

/**
 * Each action row of a source (an element whose class starts with `nc-actions`, or a
 * `<DialogFooter>`), as its offset and its markup up to the matching closing tag.
 *
 * @return list<array{int, string}>
 */
function pagesAndFormsActionRows(string $source): array
{
    $rows = [];

    preg_match_all('/<(div|form|Form|DialogFooter)\b(?:[^>"]|"[^"]*")*>/', $source, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    foreach ($tags as [[$tag, $at], [$name]]) {
        if ($name !== 'DialogFooter' && preg_match('/\sclass="nc-actions[" ]/', $tag) !== 1) {
            continue;
        }

        preg_match_all('/<(\/?)'.$name.'\b(?:[^>"]|"[^"]*")*>/', $source, $marks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $at);

        $depth = 0;
        $end = strlen($source);

        foreach ($marks as [[$mark, $offset], [$closing]]) {
            if ($closing === '/') {
                $depth--;
            } elseif (! str_ends_with($mark, '/>')) {
                $depth++;
            }

            if ($depth === 0) {
                $end = $offset + strlen($mark);

                break;
            }
        }

        $rows[] = [$at, substr($source, $at, $end - $at)];
    }

    return $rows;
}

test('every form and dialog ends in one right-aligned action row, secondary then primary', function () {
    expect(pagesAndFormsSource('components/ui/dialog/DialogFooter.vue'))
        ->toContain("cn('nc-actions', props.class)")
        ->not->toContain('flex-col-reverse');

    $misordered = [];
    $outside = [];
    $restyled = [];

    foreach (pagesAndFormsSources() as $path => $source) {
        $rows = pagesAndFormsActionRows($source);

        foreach ($rows as [, $row]) {
            $secondary = strrpos($row, 'nc-btn-secondary');
            $primary = preg_match('/nc-btn-(primary|danger)\b/', $row, $first, PREG_OFFSET_CAPTURE) === 1 ? $first[0][1] : false;

            if ($secondary !== false && $primary !== false && $secondary > $primary) {
                $misordered[] = $path;
            }
        }

        if (preg_match('/<DialogFooter\b[^>]*\sclass=/', $source) === 1) {
            $restyled[] = $path;
        }

        preg_match_all('/<button\b(?:[^>"]|"[^"]*")*>/', $source, $buttons, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($buttons as [[$button, $at]]) {
            if (! str_contains($button, 'type="submit"') || str_contains($button, 'nc-btn-block')) {
                continue;
            }

            $inRow = collect($rows)->contains(fn (array $row) => $at > $row[0] && $at < $row[0] + strlen($row[1]));

            if (! $inRow) {
                $outside[] = $path;
            }
        }
    }

    expect(array_values(array_unique($misordered)))->toBe([])
        ->and(array_values(array_unique($outside)))->toBe([])
        ->and($restyled)->toBe([]);

    foreach ([
        'components/monitoring/rules/RulesForm.vue' => 'class="flex flex-wrap items-center justify-end"',
        'components/monitoring/rules/NotificationSettings.vue' => 'class="ml-auto flex"',
        'components/monitoring/rules/MaintenanceWindows.vue' => '.submit',
        'components/TwoFactorSetupModal.vue' => 'class="flex w-full items-center gap-3"',
    ] as $path => $bespoke) {
        expect(pagesAndFormsSource($path))->toContain('class="nc-actions')->not->toContain($bespoke);
    }

    $alertSettings = pagesAndFormsSource('components/monitoring/rules/NotificationSettings.vue');

    expect(strpos($alertSettings, 'class="nc-actions"'))->toBeGreaterThan(strpos($alertSettings, "sendTest('webhook')"));
});
