<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('environment_user')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('team_members')
                ->join('environments', 'environments.team_id', '=', 'team_members.team_id')
                ->whereColumn('team_members.user_id', 'environment_user.user_id')
                ->whereColumn('environments.id', 'environment_user.environment_id')
                ->whereIn('team_members.role', ['owner', 'admin']))
            ->delete();

        DB::table('team_members')
            ->whereIn('role', ['owner', 'admin'])
            ->update(['visibility' => 'all']);

        DB::table('environment_team_invitation')
            ->whereIn('team_invitation_id', DB::table('team_invitations')->select('id')->where('role', 'admin'))
            ->delete();

        DB::table('team_invitations')
            ->where('role', 'admin')
            ->update(['visibility' => 'all']);
    }

    public function down(): void {}
};
