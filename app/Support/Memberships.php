<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;

#[Scoped]
final class Memberships
{
    /**
     * @var array<int, Collection<int, Membership>>
     */
    private array $byUser = [];

    public function of(User $user, Team $team): ?Membership
    {
        $memberships = $this->byUser[$user->id] ??= $user->teamMemberships()->get()->keyBy('team_id');

        return $memberships->get($team->id);
    }

    public function forget(int $userId): void
    {
        unset($this->byUser[$userId]);
    }

    public function flush(): void
    {
        $this->byUser = [];
    }
}
