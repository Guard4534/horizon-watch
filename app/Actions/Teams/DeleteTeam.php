<?php

namespace App\Actions\Teams;

use App\Alerts\AlertEngine;
use App\Data\Applications\ConfirmByNameData;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DeleteTeam
{
    public function __construct(private readonly AlertEngine $alerts) {}

    public function handle(Team $team, User $actor, ConfirmByNameData $data): void
    {
        $data->confirm($team->name, __('The organization name does not match.'));

        $fallbackTeam = $actor->isCurrentTeam($team)
            ? $actor->fallbackTeam($team)
            : null;

        DB::transaction(function () use ($actor, $team) {
            $this->alerts->resolveAllIn(Environment::query()->where('team_id', $team->id), CarbonImmutable::now());

            User::where('current_team_id', $team->id)
                ->where('id', '!=', $actor->id)
                ->each(function (User $affected) use ($team) {
                    $fallback = $affected->fallbackTeam($team);

                    $fallback
                        ? $affected->switchTeam($fallback)
                        : $affected->update(['current_team_id' => null]);
                });

            $team->invitations()->delete();
            $team->memberships()->delete();

            $team->alertNotifications()->delete();
            $team->alerts()->delete();
            $team->alertRules()->delete();
            $team->notificationSetting()->delete();

            $team->applications()->delete();

            $team->delete();
        });

        if ($fallbackTeam) {
            $actor->switchTeam($fallbackTeam);
        } elseif ($actor->isCurrentTeam($team)) {
            $actor->update(['current_team_id' => null]);
        }
    }
}
