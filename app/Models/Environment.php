<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use App\Enums\EnvironmentColor;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $application_id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentColor $color
 * @property string $horizon_url
 * @property string|null $basic_auth_user
 * @property string|null $basic_auth_password
 * @property int $poll_interval_seconds
 * @property Carbon|null $muted_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Application $application
 */
#[Fillable([
    'name',
    'slug',
    'color',
    'horizon_url',
    'basic_auth_user',
    'basic_auth_password',
    'poll_interval_seconds',
    'muted_until',
])]
#[Hidden(['basic_auth_password'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use GeneratesUniqueSlugs, HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Environment $environment) {
            $application = $environment->application ?? Application::findOrFail($environment->application_id);

            // Copied from the application, never mass-assignable: it is the
            // scope of the unique(['team_id', 'slug']) index, which is the
            // only thing standing between two concurrent creates and an
            // environment the wall silently loses. See the migration.
            $environment->team_id = $application->team_id;

            if (empty($environment->slug)) {
                $environment->slug = static::generateUniqueSlug($application, $environment->name);
            }
        });

        static::updating(function (Environment $environment) {
            if ($environment->isDirty('name')) {
                $application = $environment->application ?? Application::findOrFail($environment->application_id);
                $environment->slug = static::generateUniqueSlug($application, $environment->name, $environment->id);
            }
        });
    }

    /**
     * Get the application that owns this environment.
     *
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => EnvironmentColor::class,
            'basic_auth_password' => 'encrypted',
            'muted_until' => 'datetime',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate "{application-slug}-{name}", unique across the whole
     * organization (the application's team), not just within one
     * application — two applications can each have a "production".
     */
    protected static function generateUniqueSlug(Application $application, string $name, ?int $excludeId = null): string
    {
        $base = $application->slug.'-'.Str::slug($name);

        $scope = static::query()->whereHas(
            'application',
            fn ($query) => $query->where('team_id', $application->team_id),
        );

        return static::generateUniqueSlugWithin($scope, $base, $excludeId);
    }
}
