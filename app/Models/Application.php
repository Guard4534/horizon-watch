<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string $host
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Environment> $environments
 */
#[Fillable(['name', 'slug', 'host'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use GeneratesUniqueSlugs, HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Application $application) {
            if (empty($application->slug)) {
                $application->slug = static::generateUniqueSlugWithin(
                    static::query()->where('team_id', $application->team_id),
                    $application->name,
                );
            }
        });

        // No regeneration on rename: environment slugs are built from this
        // one ("{application-slug}-{name}", see Environment), so changing
        // it after creation would silently break every environment URL and
        // the metrics generator's fixed-incident seeds (phase 2 spec,
        // "Modifica del nome" decision).
    }

    /**
     * Get the team that owns this application.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the environments that belong to this application.
     *
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
