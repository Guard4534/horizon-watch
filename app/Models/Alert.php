<?php

namespace App\Models;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\EnvironmentColor;
use Carbon\CarbonImmutable;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $team_id
 * @property int|null $environment_id
 * @property AlertRuleMetric $metric
 * @property AlertSeverity $severity
 * @property string $application_name
 * @property string $environment_name
 * @property EnvironmentColor $environment_color
 * @property float $threshold
 * @property string $unit
 * @property float|null $value
 * @property array<string, mixed> $detail
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $muted_until
 * @property bool $muted_indefinitely
 * @property int|null $muted_by
 * @property CarbonImmutable|null $handled_at
 * @property int|null $handled_by
 * @property CarbonImmutable|null $last_notified_at
 * @property bool $notified
 * @property CarbonImmutable|null $digested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Environment|null $environment
 * @property-read User|null $mutedBy
 * @property-read User|null $handledBy
 * @property-read Collection<int, AlertNotification> $notifications
 */
#[Fillable([
    'team_id',
    'environment_id',
    'metric',
    'severity',
    'application_name',
    'environment_name',
    'environment_color',
    'threshold',
    'unit',
    'value',
    'detail',
    'opened_at',
    'last_seen_at',
    'resolved_at',
    'muted_until',
    'muted_indefinitely',
    'muted_by',
    'handled_at',
    'handled_by',
    'last_notified_at',
    'notified',
    'digested_at',
])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'detail' => '{}',
        'muted_indefinitely' => false,
        'notified' => false,
    ];

    public function state(CarbonImmutable $now): AlertState
    {
        return match (true) {
            $this->resolved_at !== null => AlertState::Resolved,
            $this->muted_indefinitely, $this->muted_until?->gt($now) === true => AlertState::Muted,
            default => AlertState::Open,
        };
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'muted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return HasMany<AlertNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('resolved_at'));
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('resolved_at'));
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeMutedAt(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where($this->qualifyColumn('muted_indefinitely'), true)
            ->orWhere($this->qualifyColumn('muted_until'), '>', $now));
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeUnmutedAt(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where($this->qualifyColumn('muted_indefinitely'), false)
            ->where(fn (Builder $query) => $query
                ->whereNull($this->qualifyColumn('muted_until'))
                ->orWhere($this->qualifyColumn('muted_until'), '<=', $now));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => AlertRuleMetric::class,
            'severity' => AlertSeverity::class,
            'environment_color' => EnvironmentColor::class,
            'threshold' => 'float',
            'value' => 'float',
            'detail' => 'array',
            'opened_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'muted_until' => 'immutable_datetime',
            'muted_indefinitely' => 'boolean',
            'handled_at' => 'immutable_datetime',
            'last_notified_at' => 'immutable_datetime',
            'notified' => 'boolean',
            'digested_at' => 'immutable_datetime',
        ];
    }
}
