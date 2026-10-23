<?php

namespace App\Configuration;

use App\Alerts\EffectiveRule;
use App\Data\Applications\EnvironmentFormData;
use App\Enums\AlertRuleMetric;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Support\HorizonUrl;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class ConfigurationExporter
{
    /**
     * @return array<string, mixed>
     */
    public function export(Team $team): array
    {
        return [
            'format' => ConfigurationFormat::FORMAT,
            'version' => ConfigurationFormat::VERSION,
            'exported_at' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'app_version' => Version::current(),
            'organization' => $team->name,
            'applications' => $this->applications($team),
            'alert_rules' => $this->alertRules($team),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function toJson(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public function fileName(Team $team): string
    {
        return 'horizon-watch-'.$team->slug.'-'.CarbonImmutable::now()->format('Y-m-d').'.json';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function applications(Team $team): array
    {
        return $team->applications()
            ->with(['environments' => fn ($query) => $query->orderBy('id')])
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get()
            ->map(fn (Application $application) => [
                'name' => $application->name,
                'environments' => $application->environments
                    ->map(fn (Environment $environment) => [
                        'name' => $environment->name,
                        'type' => $environment->type->value,
                        'horizon_url' => HorizonUrl::normalize(EnvironmentFormData::withoutUserinfo($environment->horizon_url)),
                        'basic_auth_user' => $environment->basic_auth_user,
                        'poll_interval_seconds' => $environment->poll_interval_seconds,
                        'polling_enabled' => $environment->polling_enabled,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alertRules(Team $team): array
    {
        $scopes = $team->environments()->pluck('environments.name')
            ->map(fn (mixed $name) => Str::lower((string) $name))
            ->unique()
            ->all();
        $order = array_flip(array_map(fn (AlertRuleMetric $metric) => $metric->value, AlertRuleMetric::cases()));

        return $team->alertRules()->get()
            ->filter(fn (AlertRule $rule) => $rule->scope === AlertRule::ORGANIZATION || in_array($rule->scope, $scopes, true))
            ->sortBy(fn (AlertRule $rule) => sprintf(
                '%d|%s|%02d',
                $rule->scope === AlertRule::ORGANIZATION ? 0 : 1,
                $rule->scope,
                $order[$rule->metric->value],
            ))
            ->map(fn (AlertRule $rule) => $this->rule($rule))
            ->filter(fn (array $row) => count($row) > 2)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(AlertRule $rule): array
    {
        $metric = $rule->metric;
        $fields = [
            'threshold' => $rule->threshold === null ? null : (int) $rule->threshold,
            'severity' => $rule->severity?->value,
            'notify_by_email' => $rule->notify_email,
            'enabled' => $rule->enabled,
            'for_minutes' => $metric->isStateRule() ? null : $rule->for_minutes,
        ];

        if ($rule->scope === AlertRule::ORGANIZATION) {
            $default = EffectiveRule::default($metric);

            $fields = [
                'threshold' => $fields['threshold'] ?? (int) $default->threshold,
                'severity' => $fields['severity'] ?? $default->severity->value,
                'notify_by_email' => $fields['notify_by_email'] ?? $default->notifyByEmail,
                'enabled' => $fields['enabled'] ?? $default->enabled,
                'for_minutes' => $metric->isStateRule() ? null : ($fields['for_minutes'] ?? $default->forMinutes),
            ];
        }

        return [
            'scope' => $rule->scope,
            'metric' => $metric->value,
            ...array_filter($fields, fn (mixed $value) => $value !== null),
        ];
    }
}
