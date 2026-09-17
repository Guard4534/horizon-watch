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
     * @var string
     */
    protected $table = 'team_members';

    /**
     * @var bool
     */
    public $incrementing = true;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Environment, $this>
     */
    public function visibleEnvironments(): BelongsToMany
    {
        return $this->belongsToMany(Environment::class, 'environment_user', 'user_id', 'environment_id', 'user_id')
            ->where('environments.team_id', $this->team_id);
    }

    /**
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
