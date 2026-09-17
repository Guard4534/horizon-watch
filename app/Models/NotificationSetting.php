<?php

namespace App\Models;

use Database\Factories\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $team_id
 * @property list<string> $recipients
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property string|null $quiet_from
 * @property string|null $quiet_to
 * @property string $timezone
 * @property int|null $repeat_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['recipients', 'webhook_url', 'webhook_secret', 'quiet_from', 'quiet_to', 'timezone', 'repeat_minutes'])]
#[Hidden(['webhook_secret'])]
class NotificationSetting extends Model
{
    /** @use HasFactory<NotificationSettingFactory> */
    use HasFactory;

    public const string DEFAULT_TIMEZONE = 'Europe/Rome';

    public const int DEFAULT_REPEAT_MINUTES = 30;

    /**
     * @var string
     */
    protected $primaryKey = 'team_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recipients' => '[]',
        'timezone' => self::DEFAULT_TIMEZONE,
        'repeat_minutes' => self::DEFAULT_REPEAT_MINUTES,
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'webhook_secret' => 'encrypted',
            'repeat_minutes' => 'integer',
        ];
    }
}
