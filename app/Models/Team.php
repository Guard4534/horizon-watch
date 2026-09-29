<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueSlugs;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, AlertRule> $alertRules
 * @property-read NotificationSetting|null $notificationSetting
 * @property-read Collection<int, Alert> $alerts
 * @property-read Collection<int, AlertNotification> $alertNotifications
 */
#[Fillable(['name', 'slug'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueSlugs, HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueSlugWithin(static::withTrashed(), $team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueSlugWithin(static::withTrashed(), $team->name, $team->id);
            }
        });
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * @return HasManyThrough<Environment, Application, $this>
     */
    public function environments(): HasManyThrough
    {
        return $this->hasManyThrough(Environment::class, Application::class);
    }

    /**
     * @return HasMany<AlertRule, $this>
     */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    /**
     * @return HasOne<NotificationSetting, $this>
     */
    public function notificationSetting(): HasOne
    {
        return $this->hasOne(NotificationSetting::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * @return HasMany<AlertNotification, $this>
     */
    public function alertNotifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
