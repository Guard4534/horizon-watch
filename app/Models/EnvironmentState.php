<?php

namespace App\Models;

use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use Carbon\CarbonImmutable;
use Database\Factories\EnvironmentStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $environment_id
 * @property CarbonImmutable $captured_at
 * @property EnvironmentStatus $status
 * @property ReadingError|null $error
 * @property HorizonStatus|null $horizon_status
 * @property list<array{hostname: string, status: string, workers: int, supervisors: int, queues: int, seenAt?: string}> $nodes
 * @property list<array{name: string, supervisor: string|null, workers: int, pending: int, waitSeconds: int, runtimeSeconds: float|null}> $queues
 * @property list<array{job: string, queue: string, exception: string, tries: int, failedAt: string}> $failed_jobs
 * @property list<array{job: string, queue: string, reservedAt: string}> $pending_jobs
 * @property int|null $latency_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Environment $environment
 */
#[Fillable([
    'environment_id',
    'captured_at',
    'status',
    'error',
    'horizon_status',
    'nodes',
    'queues',
    'failed_jobs',
    'pending_jobs',
    'latency_ms',
])]
class EnvironmentState extends Model
{
    /** @use HasFactory<EnvironmentStateFactory> */
    use HasFactory;

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
            'horizon_status' => HorizonStatus::class,
            'nodes' => 'array',
            'queues' => 'array',
            'failed_jobs' => 'array',
            'pending_jobs' => 'array',
        ];
    }
}
