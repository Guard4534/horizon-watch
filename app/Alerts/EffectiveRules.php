<?php

namespace App\Alerts;

use App\Enums\AlertRuleMetric;
use App\Enums\RuleOrigin;
use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Team;
use Illuminate\Container\Attributes\Bind;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Str;

#[Bind(EffectiveRules::class)]
#[Scoped]
final class EffectiveRules
{
    /**
     * @var array<int, array<string, array<string, AlertRule>>>
     */
    private array $rowsByTeam = [];

    public function forEnvironment(Environment $environment): RuleSet
    {
        return $this->resolve($environment->team_id, Str::lower($environment->name));
    }

    public function forScope(Team $team, string $scope): RuleSet
    {
        return $this->resolve($team->id, Str::lower($scope));
    }

    /**
     * @return array<string, int>
     */
    public function overrideCounts(Team $team): array
    {
        $counts = [];

        foreach ($this->rows($team->id) as $scope => $rows) {
            if ($scope === AlertRule::ORGANIZATION) {
                continue;
            }

            $count = count(array_filter($rows, fn (AlertRule $row) => $row->threshold !== null
                || $row->severity !== null
                || $row->notify_email !== null
                || $row->enabled !== null));

            if ($count > 0) {
                $counts[$scope] = $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    private function resolve(int $teamId, string $scope): RuleSet
    {
        $rows = $this->rows($teamId);
        $organization = $rows[AlertRule::ORGANIZATION] ?? [];
        $override = $scope === AlertRule::ORGANIZATION ? [] : ($rows[$scope] ?? []);
        $rules = [];

        foreach (AlertRuleMetric::cases() as $metric) {
            $rules[$metric->value] = $this->merge(
                EffectiveRule::default($metric),
                $organization[$metric->value] ?? null,
                $override[$metric->value] ?? null,
            );
        }

        return new RuleSet($rules);
    }

    private function merge(EffectiveRule $default, ?AlertRule $organization, ?AlertRule $override): EffectiveRule
    {
        [$threshold, $thresholdOrigin] = $this->pick($default->threshold, $organization?->threshold, $override?->threshold);
        [$severity, $severityOrigin] = $this->pick($default->severity, $organization?->severity, $override?->severity);
        [$notify, $notifyOrigin] = $this->pick($default->notifyByEmail, $organization?->notify_email, $override?->notify_email);
        [$enabled, $enabledOrigin] = $this->pick($default->enabled, $organization?->enabled, $override?->enabled);

        return new EffectiveRule(
            metric: $default->metric,
            threshold: $threshold,
            severity: $severity,
            notifyByEmail: $notify,
            enabled: $enabled,
            thresholdOrigin: $thresholdOrigin,
            severityOrigin: $severityOrigin,
            notifyOrigin: $notifyOrigin,
            enabledOrigin: $enabledOrigin,
        );
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @param  T|null  $organization
     * @param  T|null  $override
     * @return array{T, RuleOrigin}
     */
    private function pick(mixed $default, mixed $organization, mixed $override): array
    {
        if ($override !== null) {
            return [$override, RuleOrigin::Override];
        }

        return [$organization ?? $default, RuleOrigin::Organization];
    }

    /**
     * @return array<string, array<string, AlertRule>>
     */
    private function rows(int $teamId): array
    {
        if (! array_key_exists($teamId, $this->rowsByTeam)) {
            $rows = [];

            foreach (AlertRule::query()->where('team_id', $teamId)->get() as $row) {
                $rows[$row->scope][$row->metric->value] = $row;
            }

            $this->rowsByTeam[$teamId] = $rows;
        }

        return $this->rowsByTeam[$teamId];
    }
}
