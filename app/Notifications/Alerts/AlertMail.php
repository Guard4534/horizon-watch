<?php

namespace App\Notifications\Alerts;

use App\Alerts\Payloads\WebhookPayload;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\NotificationSetting;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Number;
use Throwable;

final class AlertMail
{
    public const string THEME = 'alerts.theme';

    public const string CRITICAL_COLOR = '#e0685e';

    public const string WARNING_COLOR = '#e3b857';

    public const string RESOLVED_COLOR = '#6fbf99';

    private const int MAX_NODES = 5;

    public static function message(): MailMessage
    {
        return (new MailMessage)->theme(self::THEME);
    }

    public static function subject(string $tag, Alert $alert): string
    {
        return __('[:severity] :application · :environment — :rule', [
            'severity' => $tag,
            'application' => $alert->application_name,
            'environment' => $alert->environment_name,
            'rule' => self::ruleLabel($alert->metric),
        ]);
    }

    public static function severityTag(AlertSeverity $severity): string
    {
        return match ($severity) {
            AlertSeverity::Critical => __('CRITICAL'),
            AlertSeverity::Warning => __('WARNING'),
        };
    }

    public static function severityColor(AlertSeverity $severity): string
    {
        return $severity === AlertSeverity::Critical ? self::CRITICAL_COLOR : self::WARNING_COLOR;
    }

    public static function ruleLabel(AlertRuleMetric $metric): string
    {
        return match ($metric) {
            AlertRuleMetric::HorizonMasterInactive => __('Horizon inactive'),
            AlertRuleMetric::EndpointUnreachable => __('Endpoint unreachable'),
            AlertRuleMetric::HorizonPaused => __('Horizon paused'),
            AlertRuleMetric::QueuePending => __('Pending jobs'),
            AlertRuleMetric::QueueMaxWait => __('Max wait'),
            AlertRuleMetric::JobRuntime => __('Job runtime'),
            AlertRuleMetric::JobsFailedPerHour => __('Failed jobs / hour'),
            AlertRuleMetric::WorkersMissing => __('Missing workers'),
        };
    }

    public static function headline(Alert $alert): string
    {
        $value = self::number($alert->value ?? 0);

        return match ($alert->metric) {
            AlertRuleMetric::HorizonMasterInactive => __('Horizon inactive for :minutes min', ['minutes' => $value]),
            AlertRuleMetric::EndpointUnreachable => __('Endpoint unreachable for :minutes min', ['minutes' => $value]),
            AlertRuleMetric::HorizonPaused => __('Horizon paused for :minutes min', ['minutes' => $value]),
            AlertRuleMetric::QueuePending => __(':count jobs pending', ['count' => $value]),
            AlertRuleMetric::QueueMaxWait => __('Oldest job waiting :seconds s', ['seconds' => $value]),
            AlertRuleMetric::JobRuntime => __('A job has been running for :seconds s', ['seconds' => $value]),
            AlertRuleMetric::JobsFailedPerHour => __(':count jobs failed in the last hour', ['count' => $value]),
            AlertRuleMetric::WorkersMissing => __(':count queues with waiting jobs and no worker', ['count' => $value]),
        };
    }

    public static function detail(Alert $alert): ?string
    {
        $detail = $alert->detail;

        if ($alert->metric === AlertRuleMetric::JobRuntime && is_string($detail['job'] ?? null) && is_string($detail['queue'] ?? null)) {
            return self::runningJob($detail['job'], $detail['queue']);
        }

        if ($alert->metric === AlertRuleMetric::WorkersMissing && is_array($detail['queues'] ?? null)) {
            $queues = array_slice(array_values(array_filter($detail['queues'], is_string(...))), 0, 10);

            return $queues === [] ? null : self::queues($queues);
        }

        return null;
    }

    public static function detectedAt(Alert $alert): string
    {
        $timezone = self::timezone($alert->team);

        return __('Detected at :time (:timezone)', [
            'time' => self::time($alert->opened_at, $timezone),
            'timezone' => $timezone,
        ]);
    }

    public static function resolvedAt(Alert $alert): string
    {
        $timezone = self::timezone($alert->team);
        $resolvedAt = $alert->resolved_at ?? $alert->last_seen_at;

        return __('Resolved at :time (:timezone), open for :minutes min', [
            'time' => self::time($resolvedAt, $timezone),
            'timezone' => $timezone,
            'minutes' => self::number(max(0, (int) $alert->opened_at->diffInMinutes($resolvedAt))),
        ]);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function rows(Alert $alert): array
    {
        return [
            ['label' => __('Environment'), 'value' => $alert->application_name.' · '.$alert->environment_name],
            ['label' => __('Nodes'), 'value' => self::nodes($alert)],
            ['label' => __('Rule'), 'value' => $alert->metric->value.' > '.self::withUnit($alert->threshold, $alert->unit)],
            ['label' => __('Value'), 'value' => self::withUnit($alert->value ?? 0, $alert->unit)],
        ];
    }

    public static function url(Alert $alert): ?string
    {
        return WebhookPayload::environmentUrl($alert);
    }

    public static function wallUrl(Team $team): string
    {
        return route('wall', ['current_team' => $team->slug]);
    }

    public static function withUnit(float|int $value, string $unit): string
    {
        return trim(self::number($value).' '.$unit);
    }

    public static function number(float|int $value): string
    {
        return (string) Number::format($value, maxPrecision: 2, locale: app()->getLocale());
    }

    private static function runningJob(string $job, string $queue): string
    {
        return __(':job on queue :queue', ['job' => $job, 'queue' => $queue]);
    }

    /**
     * @param  list<string>  $queues
     */
    private static function queues(array $queues): string
    {
        return __('Queues: :queues', ['queues' => implode(', ', $queues)]);
    }

    private static function nodes(Alert $alert): string
    {
        $names = array_column($alert->environment->state->nodes ?? [], 'hostname');

        if ($names === []) {
            return '—';
        }

        $shown = implode(', ', array_slice($names, 0, self::MAX_NODES));

        return count($names) > self::MAX_NODES ? $shown.' +'.(count($names) - self::MAX_NODES) : $shown;
    }

    private static function timezone(?Team $team): string
    {
        return $team === null
            ? NotificationSetting::DEFAULT_TIMEZONE
            : (NotificationSetting::query()->whereKey($team->id)->value('timezone') ?? NotificationSetting::DEFAULT_TIMEZONE);
    }

    private static function time(CarbonImmutable $at, string $timezone): string
    {
        try {
            return $at->setTimezone($timezone)->format('Y-m-d H:i');
        } catch (Throwable) {
            return $at->setTimezone(NotificationSetting::DEFAULT_TIMEZONE)->format('Y-m-d H:i');
        }
    }
}
