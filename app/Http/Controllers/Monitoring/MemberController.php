<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Teams\ChangeMemberRole;
use App\Actions\Teams\ChangeMemberVisibility;
use App\Actions\Teams\RemoveMember;
use App\Data\Teams\UpdateMemberData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Queries\MembersQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    public function index(Request $request, Team $current_team, MembersQuery $query): Response
    {
        return Inertia::render('monitoring/Members', [
            'page' => $query->handle($current_team, $request->user()),
        ]);
    }

    public function update(
        Request $request,
        Team $current_team,
        User $user,
        UpdateMemberData $data,
        ChangeMemberRole $changeMemberRole,
        ChangeMemberVisibility $changeMemberVisibility,
    ): RedirectResponse {
        Gate::authorize('updateMember', [$current_team, $user]);

        if ($data->role !== null) {
            $changeMemberRole->handle($current_team, $request->user(), $user, $data->role);
        }

        if ($data->visibility !== null) {
            $changeMemberVisibility->handle($current_team, $user, $data->visibility, $data->environmentIds);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member updated.')]);

        return back();
    }

    public function destroy(
        Request $request,
        Team $current_team,
        User $user,
        RemoveMember $removeMember,
    ): RedirectResponse {
        Gate::authorize('removeMember', [$current_team, $user]);

        $removeMember->handle($current_team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return $request->user()?->is($user)
            ? to_route('home')
            : back();
    }
}
