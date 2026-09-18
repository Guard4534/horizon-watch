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
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes += [
            'timezone' => self::defaultTimezone(),
            'repeat_minutes' => self::defaultRepeatMinutes(),
        ];

        parent::__construct($attributes);
    }

    public static function defaultTimezone(): string
    {
        return config()->string('horizon-watch.notifications.default_timezone');
    }

    public static function defaultRepeatMinutes(): int
    {
        return config()->integer('horizon-watch.notifications.default_repeat_minutes');
    }

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
