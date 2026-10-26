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
use Illuminate\Support\Collection as BaseCollection;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Environment> $environments
 */
#[Fillable(['name', 'slug'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use GeneratesUniqueSlugs, HasFactory;

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
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * @param  BaseCollection<int, Environment>  $environments
     */
    public static function primaryEnvironment(BaseCollection $environments): ?Environment
    {
        $ordered = $environments->sortBy('id')->values();

        return $ordered->first(fn (Environment $environment) => $environment->type->isProduction())
            ?? $ordered->first();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
