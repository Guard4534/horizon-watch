<?php

namespace App\Alerts\Payloads;

use App\Models\Alert;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class WebhookPayload
{
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
            'value' => $alert->value,
            'threshold' => $alert->threshold,
            'unit' => $alert->unit,
            'application' => $alert->application_name,
            'environment' => $alert->environment_name,
            'opened_at' => $alert->opened_at->utc()->toIso8601ZuluString(),
            'resolved_at' => $alert->resolved_at?->utc()->toIso8601ZuluString(),
            'url' => self::environmentUrl($alert),
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
