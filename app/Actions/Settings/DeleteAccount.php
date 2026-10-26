<?php

namespace App\Actions\Settings;

use App\Actions\Teams\DeleteTeam;
use App\Data\Settings\AccountDeletionData;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\SetupStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function __construct(private readonly DeleteTeam $deleteTeam) {}

    public function summary(User $user): AccountDeletionData
    {
        [$shared, $sole] = $this->ownedTeams($user);

        return new AccountDeletionData(
            sharedOrganizations: $shared->pluck('name')->values()->all(),
            soleOrganizations: $sole->pluck('name')->values()->all(),
        );
    }

    public function refusal(User $user): ?string
    {
        [$shared] = $this->ownedTeams($user);

        return $this->refusalFor($shared);
    }

    /**
     * @throws ValidationException
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            [$shared, $sole] = $this->ownedTeams($user, lock: true);

            $refusal = $this->refusalFor($shared);

            if ($refusal !== null) {
                throw ValidationException::withMessages(['account' => $refusal]);
            }

            foreach ($sole as $team) {
                $this->deleteTeam->purge($team, $user);
            }

            $user->delete();
        });

        if (! User::query()->exists()) {
            SetupStatus::forget();
        }
    }

    /**
     * @return array{0: Collection<int, Team>, 1: Collection<int, Team>}
     */
    private function ownedTeams(User $user, bool $lock = false): array
    {
        $teamIds = $user->teamMemberships()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->filter(fn (Membership $membership) => $membership->role === TeamRole::Owner)
            ->pluck('team_id');

        if ($lock && $teamIds->isNotEmpty()) {
            TeamInvitation::query()
                ->whereIn('team_id', $teamIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
        }

        $sharedIds = Membership::query()
            ->whereIn('team_id', $teamIds)
            ->where('user_id', '!=', $user->id)
            ->distinct()
            ->pluck('team_id');

        $teams = Team::query()
            ->whereKey($teamIds)
            ->orderByRaw('LOWER(name)')
            ->get();

        [$shared, $sole] = $teams->partition(fn (Team $team) => $sharedIds->contains($team->id));

        return [$shared->values(), $sole->values()];
    }

    /**
     * @param  Collection<int, Team>  $shared
     */
    private function refusalFor(Collection $shared): ?string
    {
        if ($shared->isEmpty()) {
            return null;
        }

        return __('You own organizations that other people belong to: :organizations. Transfer their ownership from the Members page, or delete them, before deleting your account.', [
            'organizations' => $shared->pluck('name')->join(', '),
        ]);
    }
}
