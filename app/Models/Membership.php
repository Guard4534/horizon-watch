<?php

namespace App\Models;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property TeamRole $role
 * @property MemberVisibility $visibility
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'role', 'visibility'])]
class Membership extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'team_members';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the team that the membership belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user that belongs to this membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the environments explicitly granted to this member. Only
     * meaningful when visibility is "manual" — other visibilities compute
     * their environments from the team's applications instead.
     *
     * @return BelongsToMany<Environment, $this>
     */
    public function visibleEnvironments(): BelongsToMany
    {
        return $this->belongsToMany(Environment::class, 'environment_user', 'user_id', 'environment_id', 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'visibility' => MemberVisibility::class,
        ];
    }
}
