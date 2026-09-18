<?php

namespace App\Models;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $scope
 * @property AlertRuleMetric $metric
 * @property float|null $threshold
 * @property AlertSeverity|null $severity
 * @property bool|null $notify_email
 * @property bool|null $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['scope', 'metric', 'threshold', 'severity', 'notify_email', 'enabled'])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    public const string ORGANIZATION = 'organization';

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function scope(): Attribute
    {
        return Attribute::set(fn (string $value): string => Str::lower($value));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => AlertRuleMetric::class,
            'severity' => AlertSeverity::class,
            'threshold' => 'float',
            'notify_email' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
