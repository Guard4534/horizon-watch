<?php

namespace App\Alerts\Payloads;

use App\Models\Alert;
use App\Models\Team;
use App\Notifications\Alerts\AlertMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WebhookPayload
{
    public const int VERSION = 1;

    public const string OPENED = 'alert.opened';

    public const string REPEATED = 'alert.repeated';

    public const string RESOLVED = 'alert.resolved';

    public const string DIGEST = 'alert.digest';

    public const string TEST = 'test';

    /**
     * @return array<string, mixed>
     */
    public static function forAlert(Alert $alert, string $event): array
    {
        return [
            'version' => self::VERSION,
            'event' => $event,
            'delivery_id' => null,
            'alert' => self::alert($alert),
            'organization' => self::organization($alert->team),
            'sent_at' => null,
        ];
    }

    /**
     * @param  Collection<int, Alert>  $alerts
     * @return array<string, mixed>
     */
    public static function forDigest(Team $team, Collection $alerts): array
    {
        return [
            'version' => self::VERSION,
            'event' => self::DIGEST,
            'delivery_id' => null,
            'alerts' => $alerts->map(self::alert(...))->values()->all(),
            'organization' => self::organization($team),
            'sent_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forTest(Team $team): array
    {
        return [
            'version' => self::VERSION,
            'event' => self::TEST,
            'delivery_id' => null,
            'alert' => null,
            'organization' => self::organization($team),
            'sent_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stamped(array $payload, CarbonImmutable $at, string $deliveryId): array
    {
        $payload['delivery_id'] = $deliveryId;
        $payload['sent_at'] = $at->utc()->toIso8601ZuluString();

        return $payload;
    }

    public static function environmentUrl(Alert $alert): ?string
    {
        $environment = $alert->environment;
        /** @var Team|null $team */
        $team = $alert->team;

        if ($environment === null || $team === null) {
            return null;
        }

        return route('environments.show', ['current_team' => $team->slug, 'environment' => $environment->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function alert(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'rule' => $alert->metric->value,
            'severity' => $alert->severity->value,
            'summary' => self::summary($alert),
            'value' => $alert->value,
            'threshold' => $alert->threshold,
            'unit' => $alert->unit,
            'detail' => self::detail($alert),
            'application' => $alert->application_name,
            'environment' => $alert->environment_name,
            'opened_at' => $alert->opened_at->utc()->toIso8601ZuluString(),
            'resolved_at' => $alert->resolved_at?->utc()->toIso8601ZuluString(),
            'url' => self::environmentUrl($alert),
        ];
    }

    private static function summary(Alert $alert): string
    {
        return AlertMail::where($alert).' — '.($alert->resolved_at === null ? AlertMail::headline($alert) : AlertMail::resolvedHeadline($alert));
    }

    /**
     * @return array{value_is_lower_bound: bool, longest_job: string|null, longest_job_queue: string|null, queues_without_workers: list<string>}
     */
    private static function detail(Alert $alert): array
    {
        $detail = $alert->detail;

        return [
            'value_is_lower_bound' => ($detail['atLeast'] ?? false) === true,
            'longest_job' => is_string($detail['job'] ?? null) ? $detail['job'] : null,
            'longest_job_queue' => is_string($detail['queue'] ?? null) ? $detail['queue'] : null,
            'queues_without_workers' => is_array($detail['queues'] ?? null) ? array_values(array_filter($detail['queues'], is_string(...))) : [],
        ];
    }

    /**
     * @return array{name: string, slug: string}|null
     */
    private static function organization(?Team $team): ?array
    {
        return $team === null ? null : ['name' => $team->name, 'slug' => $team->slug];
    }
}
