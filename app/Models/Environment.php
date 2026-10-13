<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use App\Concerns\ReplacesUnreadableSecrets;
use App\Enums\EnvironmentColor;
use Carbon\CarbonImmutable;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property bool $polling_enabled
 * @property bool $is_production
 * @property CarbonImmutable|null $last_polled_at
 * @property CarbonImmutable|null $next_poll_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Application $application
 * @property-read Collection<int, EnvironmentSnapshot> $snapshots
 * @property-read EnvironmentState|null $state
 */
#[Fillable([
    'name',
    'slug',
    'color',
    'horizon_url',
    'basic_auth_user',
    'basic_auth_password',
    'poll_interval_seconds',
    'polling_enabled',
    'is_production',
])]
#[Hidden(['basic_auth_password'])]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use GeneratesUniqueSlugs, HasFactory, ReplacesUnreadableSecrets;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'polling_enabled' => true,
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Environment $environment) {
            $application = $environment->application ?? Application::findOrFail($environment->application_id);

            $environment->team_id = $application->team_id;

            if (! array_key_exists('is_production', $environment->getAttributes())) {
                $environment->is_production = static::looksLikeProduction($environment->name);
            }

            if (empty($environment->slug)) {
                $environment->slug = static::generateUniqueSlug($application, $environment->name);
            }
        });
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<EnvironmentSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EnvironmentSnapshot::class);
    }

    /**
     * @return HasOne<EnvironmentState, $this>
     */
    public function state(): HasOne
    {
        return $this->hasOne(EnvironmentState::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => EnvironmentColor::class,
            'basic_auth_password' => 'encrypted',
            'polling_enabled' => 'boolean',
            'is_production' => 'boolean',
            'last_polled_at' => 'immutable_datetime',
            'next_poll_at' => 'immutable_datetime',
        ];
    }

    public static function looksLikeProduction(string $name): bool
    {
        return preg_match('/^(production|prod|prd|live)([-_.\s].*)?$/i', trim($name)) === 1;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function generateUniqueSlug(Application $application, string $name): string
    {
        $environmentSlug = Str::slug($name);
        $base = Str::substr($application->slug, 0, self::MAX_SLUG_BASE_LENGTH - strlen($environmentSlug) - 1).'-'.$environmentSlug;

        $scope = static::query()->whereHas(
            'application',
            fn ($query) => $query->where('team_id', $application->team_id),
        );

        return static::generateUniqueSlugWithin($scope, $base);
    }
}
