<?php

namespace App\Models;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use Carbon\CarbonImmutable;
use Database\Factories\EnvironmentSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $environment_id
 * @property CarbonImmutable $captured_at
 * @property EnvironmentStatus $status
 * @property ReadingError|null $error
 * @property Collection<int, AlertRuleMetric> $breaches
 * @property int $pending
 * @property int $max_wait_seconds
 * @property int $jobs_per_minute
 * @property int $failed_in_window
 * @property int $failed_window_minutes
 * @property int $failed_last_hour
 * @property int $workers
 * @property int $node_count
 * @property int|null $latency_ms
 * @property-read Environment $environment
 */
#[Fillable([
    'environment_id',
    'captured_at',
    'status',
    'error',
    'breaches',
    'pending',
    'max_wait_seconds',
    'jobs_per_minute',
    'failed_in_window',
    'failed_window_minutes',
    'failed_last_hour',
    'workers',
    'node_count',
    'latency_ms',
])]
class EnvironmentSnapshot extends Model
{
    /** @use HasFactory<EnvironmentSnapshotFactory> */
    use HasFactory;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'status' => EnvironmentStatus::class,
            'error' => ReadingError::class,
            'breaches' => AsEnumCollection::of(AlertRuleMetric::class),
        ];
    }
}
