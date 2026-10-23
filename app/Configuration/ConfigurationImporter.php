<?php

namespace App\Configuration;

use App\Actions\Alerts\UpdateAlertRules;
use App\Actions\Environments\AddEnvironment;
use App\Actions\Environments\UpdateEnvironment;
use App\Alerts\EffectiveRule;
use App\Data\Alerts\AlertRuleInputData;
use App\Data\Alerts\AlertRulesInputData;
use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Configuration\ImportItemData;
use App\Data\Configuration\ImportPreviewData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentType;
use App\Enums\ImportItemKind;
use App\Enums\ImportItemStatus;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Rules\StoredPasswordStaysWithItsAddress;
use App\Support\HorizonUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ConfigurationImporter
{
    private const string NO_NAME = '—';

    public function __construct(
        private AddEnvironment $addEnvironment,
        private UpdateEnvironment $updateEnvironment,
        private UpdateAlertRules $updateAlertRules,
    ) {}

    public function preview(Team $team, ConfigurationDocument $document, string $fileName): ImportPreviewData
    {
        $items = array_map(fn (PlannedItem $planned) => $planned->item, $this->plan($team, $document));

        return new ImportPreviewData(
            fileName: $fileName,
            organization: $document->organization(),
            exportedAt: $document->exportedAt(),
            items: $items,
            blocked: self::blocks($items),
        );
    }

    /**
     * @throws ImportBlocked
     */
    public function apply(Team $team, ConfigurationDocument $document, bool $update): ImportSummary
    {
        return DB::transaction(function () use ($team, $document, $update) {
            Team::query()->whereKey($team->id)->lockForUpdate()->first();

            $plan = $this->plan($team, $document);

            if (self::blocks(array_map(fn (PlannedItem $planned) => $planned->item, $plan))) {
                throw new ImportBlocked;
            }

            $created = $updated = $skipped = $passwordNeeded = 0;

            /** @var array<string, Application> $applications */
            $applications = [];

            foreach ($plan as $planned) {
                $status = $planned->item->status;
                $writes = $status === ImportItemStatus::New || ($status === ImportItemStatus::Changed && $update);

                if ($planned->item->kind === ImportItemKind::Application) {
                    $applications[$planned->key] = $writes
                        ? $this->writeApplication($team, $planned)
                        : ($planned->application ?? throw new LogicException('A skipped application exists.'));
                } elseif ($writes && $planned->item->kind === ImportItemKind::Environment) {
                    $this->writeEnvironment(
                        $applications[$planned->key] ?? throw new LogicException('An environment follows its application.'),
                        $planned,
                    );
                } elseif ($writes) {
                    $this->updateAlertRules->handle($team, $planned->key, new AlertRulesInputData([
                        $planned->rule ?? throw new LogicException('A rule item carries its rule.'),
                    ]));
                }

                if (! $writes) {
                    $skipped++;

                    continue;
                }

                if ($status === ImportItemStatus::New) {
                    $created++;
                } else {
                    $updated++;
                }

                if ($planned->item->passwordNeeded) {
                    $passwordNeeded++;
                }
            }

            return new ImportSummary($created, $updated, $skipped, $passwordNeeded);
        });
    }

    /**
     * @return array<int, PlannedItem>
     */
    private function plan(Team $team, ConfigurationDocument $document): array
    {
        $stored = $team->applications()->with('environments')->orderBy('id')->get()
            ->groupBy(fn (Application $application) => Str::lower($application->name));
        $cap = config()->integer('horizon-watch.limits.wizard_environments');

        $plan = [];
        $seenApplications = [];

        foreach ($document->applications() as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : null;
            $key = Str::lower($name ?? '');
            /** @var Collection<int, Application> $matches */
            $matches = $name === null ? collect() : $stored->get($key, collect());
            $existing = $matches->first();
            $environments = $entry['environments'] ?? [];

            $errors = Validator::make(['name' => $name], ['name' => ApplicationFormData::rules()['name']])->errors()->all();

            if ($name !== null && in_array($key, $seenApplications, true)) {
                $errors[] = __('This file lists the application :name twice.', ['name' => $name]);
            }

            if ($matches->count() > 1) {
                $errors[] = __('Two applications of this organization are named :name: rename one of them first.', ['name' => $name]);
            }

            if (! is_array($environments) || ! array_is_list($environments)) {
                $errors[] = __('This application lists its environments in the wrong shape.');
                $environments = [];
            } elseif (count($environments) > $cap) {
                $errors[] = __('An application can bring at most :max environments in one import.', ['max' => $cap]);
            }

            if ($name !== null) {
                $seenApplications[] = $key;
            }

            $plan[] = new PlannedItem(
                item: $this->item(
                    kind: ImportItemKind::Application,
                    label: $name ?? self::NO_NAME,
                    errors: $errors,
                    existing: $existing !== null,
                    changes: $existing !== null && $existing->name !== $name ? ['name'] : [],
                ),
                key: $key,
                name: $name,
                application: $existing,
            );

            $seenEnvironments = [];

            foreach ($environments as $environment) {
                $plan[] = $this->planEnvironment(
                    is_array($environment) ? $environment : [],
                    $key,
                    $name ?? self::NO_NAME,
                    $existing,
                    $seenEnvironments,
                );
            }
        }

        $scopes = array_values(array_unique([
            ...$team->environments()->pluck('environments.name')->map(fn (mixed $name) => Str::lower((string) $name))->all(),
            ...collect($plan)
                ->filter(fn (PlannedItem $planned) => $planned->item->kind === ImportItemKind::Environment
                    && $planned->item->status !== ImportItemStatus::Invalid)
                ->map(fn (PlannedItem $planned) => Str::lower($planned->item->label))
                ->all(),
        ]));
        $rules = $team->alertRules()->get()->keyBy(fn (AlertRule $rule) => $rule->scope.'|'.$rule->metric->value);
        $seenRules = [];

        foreach ($document->alertRules() as $entry) {
            $plan[] = $this->planRule(is_array($entry) ? $entry : [], $scopes, $rules, $seenRules);
        }

        return $plan;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @param  array<int, string>  $seen
     */
    private function planEnvironment(array $entry, string $applicationKey, string $applicationName, ?Application $application, array &$seen): PlannedItem
    {
        $url = $entry['horizon_url'] ?? null;
        $user = $entry['basic_auth_user'] ?? null;
        $payload = [
            'name' => $entry['name'] ?? null,
            'type' => $entry['type'] ?? null,
            'horizonUrl' => is_string($url) ? HorizonUrl::normalize($url) : $url,
            'basicAuthUser' => is_string($user) && trim($user) === '' ? null : $user,
            'pollIntervalSeconds' => $entry['poll_interval_seconds'] ?? config()->integer('horizon-watch.readings.poll_interval_seconds.default'),
            'pollingEnabled' => $entry['polling_enabled'] ?? true,
        ];
        $name = is_string($payload['name']) ? $payload['name'] : null;
        $label = $name ?? self::NO_NAME;

        $errors = Validator::make($payload, EnvironmentFormData::importRules(), [], EnvironmentFormData::attributes())->errors()->all();

        if ($name !== null && in_array($name, $seen, true)) {
            $errors[] = __('This file lists the environment :name twice in this application.', ['name' => $name]);
        }

        if ($name !== null) {
            $seen[] = $name;
        }

        if ($errors !== []) {
            return new PlannedItem(
                item: $this->item(ImportItemKind::Environment, $label, $errors, false, [], application: $applicationName),
                key: $applicationKey,
            );
        }

        $type = EnvironmentType::from((string) $payload['type']);
        $data = new EnvironmentFormData(
            name: (string) $name,
            type: $type,
            horizonUrl: (string) $payload['horizonUrl'],
            pollIntervalSeconds: (int) $payload['pollIntervalSeconds'],
            basicAuthUser: is_string($payload['basicAuthUser']) ? $payload['basicAuthUser'] : null,
            basicAuthPassword: null,
            pollingEnabled: filter_var($payload['pollingEnabled'], FILTER_VALIDATE_BOOLEAN),
        );

        /** @var Environment|null $stored */
        $stored = $application?->environments->firstWhere('name', $name);

        $keepsPassword = $stored !== null
            && $data->basicAuthUser !== null
            && $data->basicAuthUser === $stored->basic_auth_user
            && StoredPasswordStaysWithItsAddress::hasStoredPassword($stored)
            && EnvironmentFormData::sameAddress($data->horizonUrl, $stored->horizon_url);
        $passwordNeeded = $data->basicAuthUser !== null && ! $keepsPassword;

        if ($passwordNeeded) {
            $data->pollingEnabled = false;
        }

        $changes = $stored === null ? [] : array_keys(array_filter([
            'type' => $stored->type !== $data->type,
            'horizon_url' => HorizonUrl::normalize(EnvironmentFormData::withoutUserinfo($stored->horizon_url)) !== $data->horizonUrl,
            'basic_auth_user' => $stored->basic_auth_user !== $data->basicAuthUser,
            'poll_interval_seconds' => $stored->poll_interval_seconds !== $data->pollIntervalSeconds,
            'polling_enabled' => $stored->polling_enabled !== $data->pollingEnabled,
        ]));

        return new PlannedItem(
            item: $this->item(
                kind: ImportItemKind::Environment,
                label: (string) $name,
                errors: [],
                existing: $stored !== null,
                changes: $changes,
                application: $applicationName,
                type: $type,
                passwordNeeded: $passwordNeeded,
            ),
            key: $applicationKey,
            environment: $stored,
            environmentData: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @param  array<int, string>  $scopes
     * @param  Collection<string, AlertRule>  $stored
     * @param  array<int, string>  $seen
     */
    private function planRule(array $entry, array $scopes, Collection $stored, array &$seen): PlannedItem
    {
        $scope = is_string($entry['scope'] ?? null) ? Str::lower(trim($entry['scope'])) : null;
        $metricValue = is_string($entry['metric'] ?? null) ? $entry['metric'] : null;
        $metric = $metricValue === null ? null : AlertRuleMetric::tryFrom($metricValue);
        $organization = $scope === AlertRule::ORGANIZATION;

        $errors = Validator::make(['scope' => $scope], ['scope' => ['required', 'string', 'max:60']])->errors()->all();
        $rule = null;

        try {
            $rule = AlertRuleInputData::validateAndCreate([
                AlertRulesInputData::SCOPE => $scope ?? AlertRule::ORGANIZATION,
                'metric' => $entry['metric'] ?? null,
                'threshold' => $entry['threshold'] ?? null,
                'severity' => $entry['severity'] ?? null,
                'notifyByEmail' => $entry['notify_by_email'] ?? null,
                'enabled' => $entry['enabled'] ?? null,
                'forMinutes' => $entry['for_minutes'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            $errors = [...$errors, ...$exception->validator->errors()->all()];
        }

        if ($scope !== null && ! $organization && ! in_array($scope, $scopes, true)) {
            $errors[] = __('No environment is named :scope, in this organization or in the file.', ['scope' => $scope]);
        }

        if ($rule !== null && ! $organization && $rule->isEmpty()) {
            $errors[] = __('This rule sets nothing: give it at least one field.');
        }

        $ruleKey = ($scope ?? '').'|'.($metricValue ?? '');

        if ($scope !== null && $metric !== null && in_array($ruleKey, $seen, true)) {
            $errors[] = __('This file lists this rule twice for :scope.', ['scope' => $scope]);
        }

        $seen[] = $ruleKey;
        $existing = $stored->get($ruleKey);

        $changes = [];

        if ($rule !== null && $existing !== null) {
            $default = $organization ? EffectiveRule::default($rule->metric) : null;
            $before = $this->ruleFields($rule->metric, $default, $existing->threshold, $existing->severity, $existing->notify_email, $existing->enabled, $existing->for_minutes);
            $after = $this->ruleFields($rule->metric, $default, $rule->threshold === null ? null : (float) $rule->threshold, $rule->severity, $rule->notifyByEmail, $rule->enabled, $rule->forMinutes);
            $changes = array_keys(array_filter($before, fn (mixed $value, string $field) => $value !== $after[$field], ARRAY_FILTER_USE_BOTH));
        }

        return new PlannedItem(
            item: $this->item(
                kind: ImportItemKind::AlertRule,
                label: ($scope ?? self::NO_NAME).' / '.($metricValue ?? self::NO_NAME),
                errors: $errors,
                existing: $existing !== null,
                changes: $changes,
                scope: $scope,
                metric: $metric,
            ),
            key: $scope ?? AlertRule::ORGANIZATION,
            rule: $rule,
        );
    }

    /**
     * The fields a rule row compares on, in the file's keys. An organization row that leaves a field
     * empty means the default, as the export writes it.
     *
     * @return array<string, mixed>
     */
    private function ruleFields(
        AlertRuleMetric $metric,
        ?EffectiveRule $default,
        ?float $threshold,
        ?AlertSeverity $severity,
        ?bool $notifyByEmail,
        ?bool $enabled,
        ?int $forMinutes,
    ): array {
        return [
            'threshold' => $threshold ?? $default?->threshold,
            'severity' => $severity ?? $default?->severity,
            'notify_by_email' => $notifyByEmail ?? $default?->notifyByEmail,
            'enabled' => $enabled ?? $default?->enabled,
            'for_minutes' => $metric->isStateRule() ? null : ($forMinutes ?? $default?->forMinutes),
        ];
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $changes
     */
    private function item(
        ImportItemKind $kind,
        string $label,
        array $errors,
        bool $existing,
        array $changes,
        ?string $application = null,
        ?string $scope = null,
        ?AlertRuleMetric $metric = null,
        ?EnvironmentType $type = null,
        bool $passwordNeeded = false,
    ): ImportItemData {
        $errors = array_values(array_unique($errors));

        return new ImportItemData(
            kind: $kind,
            label: $label,
            application: $application,
            scope: $scope,
            metric: $metric,
            type: $type,
            status: match (true) {
                $errors !== [] => ImportItemStatus::Invalid,
                ! $existing => ImportItemStatus::New,
                $changes !== [] => ImportItemStatus::Changed,
                default => ImportItemStatus::Unchanged,
            },
            changes: $errors === [] ? $changes : [],
            errors: $errors,
            passwordNeeded: $errors === [] && $passwordNeeded,
        );
    }

    /**
     * @param  array<int, ImportItemData>  $items
     */
    private static function blocks(array $items): bool
    {
        return collect($items)->contains(fn (ImportItemData $item) => $item->status === ImportItemStatus::Invalid);
    }

    private function writeApplication(Team $team, PlannedItem $planned): Application
    {
        $name = $planned->name ?? throw new LogicException('An application item carries its name.');

        if ($planned->application === null) {
            return $team->applications()->create(['name' => $name]);
        }

        $planned->application->update(['name' => $name]);

        return $planned->application;
    }

    private function writeEnvironment(Application $application, PlannedItem $planned): void
    {
        $data = $planned->environmentData ?? throw new LogicException('An environment item carries its data.');
        $environment = $planned->environment;

        if ($environment === null) {
            $this->addEnvironment->handle($application, $data);

            return;
        }

        if ($planned->item->passwordNeeded && StoredPasswordStaysWithItsAddress::hasStoredPassword($environment)) {
            $environment->forceFill(['basic_auth_password' => null])->save();
        }

        $this->updateEnvironment->handle($environment, $data);
    }
}
