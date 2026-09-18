<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use Carbon\CarbonImmutable;
use Database\Factories\AlertNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property string|null $alert_id
 * @property SentNotificationKind $kind
 * @property NotificationChannel $channel
 * @property string $target
 * @property DeliveryStatus $status
 * @property string|null $error
 * @property int|null $environment_count
 * @property CarbonImmutable $sent_at
 * @property string|null $delivery_id
 * @property-read Team $team
 * @property-read Alert|null $alert
 */
#[Fillable(['team_id', 'alert_id', 'kind', 'channel', 'target', 'status', 'error', 'sent_at', 'environment_count', 'delivery_id'])]
class AlertNotification extends Model
{
    /** @use HasFactory<AlertNotificationFactory> */
    use HasFactory;

    const CREATED_AT = null;

    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SentNotificationKind::class,
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'sent_at' => 'immutable_datetime',
            'environment_count' => 'integer',
        ];
    }
}
