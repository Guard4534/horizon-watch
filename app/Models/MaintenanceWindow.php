<?php

namespace App\Models;

use App\Enums\MaintenanceScope;
use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property MaintenanceScope $scope
 * @property int|null $application_id
 * @property int|null $environment_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Application|null $application
 * @property-read Environment|null $environment
 * @property-read User|null $creator
 */
#[Fillable(['scope', 'application_id', 'environment_id', 'starts_at', 'ends_at', 'reason', 'created_by'])]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    public function isActiveAt(CarbonImmutable $at): bool
    {
        return $this->starts_at->lte($at) && $this->ends_at->gt($at);
    }

    public function covers(Environment $environment): bool
    {
        return $this->team_id === $environment->team_id && match ($this->scope) {
            MaintenanceScope::Organization => true,
            MaintenanceScope::Application => $this->application_id === $environment->application_id,
            MaintenanceScope::Environment => $this->environment_id === $environment->id,
        };
    }

    /**
     * @return Builder<Environment>
     */
    public function coveredEnvironments(): Builder
    {
        $environments = Environment::query()->where('environments.team_id', $this->team_id);

        return match ($this->scope) {
            MaintenanceScope::Organization => $environments,
            MaintenanceScope::Application => $environments->where('environments.application_id', $this->application_id),
            MaintenanceScope::Environment => $environments->whereKey($this->environment_id),
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
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<MaintenanceWindow>  $query
     * @return Builder<MaintenanceWindow>
     */
    public function scopeActiveAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query
            ->where($this->qualifyColumn('starts_at'), '<=', $at->utc())
            ->where($this->qualifyColumn('ends_at'), '>', $at->utc());
    }

    /**
     * @param  Builder<MaintenanceWindow>  $query
     * @return Builder<MaintenanceWindow>
     */
    public function scopeCovering(Builder $query, Environment $environment): Builder
    {
        return $query
            ->where($this->qualifyColumn('team_id'), $environment->team_id)
            ->where(fn (Builder $covering) => $covering
                ->where($this->qualifyColumn('scope'), MaintenanceScope::Organization)
                ->orWhere($this->qualifyColumn('application_id'), $environment->application_id)
                ->orWhere($this->qualifyColumn('environment_id'), $environment->id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => MaintenanceScope::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
