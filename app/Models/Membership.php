<?php

namespace App\Models;

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function clearEnvironmentGrants(): void
    {
        DB::table('environment_user')
            ->where('user_id', $this->user_id)
            ->whereIn('environment_id', $this->team->environments()->pluck('environments.id'))
            ->delete();
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
