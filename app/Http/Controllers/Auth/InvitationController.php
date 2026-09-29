<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\AcceptInvitation;
use App\Actions\Teams\DeclineInvitation;
use App\Actions\Teams\RegisterInvitedUser;
use App\Data\Pages\InvitationPageData;
use App\Data\Teams\AcceptInvitationData;
use App\Enums\MemberVisibility;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $code): Response
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();
        $state = $this->stateFor($invitation, $user);
        $canSeeDetails = $state === 'open';

        return Inertia::render('auth/Invitation', [
            'page' => new InvitationPageData(
                code: $invitation->code,
                organizationName: $canSeeDetails ? $invitation->team->name : null,
                roleLabel: $canSeeDetails ? $invitation->role->label() : null,
                visibilityLabel: $canSeeDetails ? $invitation->visibility->label() : null,
                email: $canSeeDetails ? $invitation->email : null,
                visibleEnvironmentNames: $canSeeDetails && $invitation->visibility === MemberVisibility::Manual
                    ? $invitation->environments->pluck('name')->all()
                    : [],
                state: $state,
                authenticated: $user !== null,
            ),
        ]);
    }

    public function register(Request $request, string $code, AcceptInvitationData $data, RegisterInvitedUser $registerInvitedUser, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);

        abort_unless($this->stateFor($invitation, null) === 'open', 410);

        $user = $registerInvitedUser->handle($invitation, $data, $acceptInvitation);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('wall', ['current_team' => $user->currentTeam?->slug]);
    }

    public function accept(string $code, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();

        abort_if($user === null, 403);
        abort_unless($this->sameEmail($invitation, $user), 403);
        abort_unless($this->stateFor($invitation, $user) === 'open', 410);

        $acceptInvitation->handle($invitation, $user);

        $this->success(__('Invitation accepted.'));

        return to_route('wall', ['current_team' => $user->currentTeam?->slug]);
    }

    public function decline(string $code, DeclineInvitation $declineInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();

        abort_if($user === null, 403);
        abort_unless($this->sameEmail($invitation, $user), 403);

        abort_unless($this->stateFor($invitation, $user) === 'open', 410);

        $declineInvitation->handle($invitation);

        $this->success(__('Invitation declined.'));

        return to_route('wall');
    }

    private function findOrFail(string $code): TeamInvitation
    {
        return TeamInvitation::query()
            ->with(['team', 'environments'])
            ->where('code', $code)
            ->firstOr(fn () => abort(404));
    }

    private function sameEmail(TeamInvitation $invitation, User $user): bool
    {
        return $invitation->email === $user->email;
    }

    private function stateFor(TeamInvitation $invitation, ?User $user): string
    {
        return match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isRevoked() => 'revoked',
            $invitation->isExpired() => 'expired',
            $user !== null && ! $this->sameEmail($invitation, $user) => 'wrong_account',
            $user === null && $this->accountExists($invitation) => 'sign_in_required',
            default => 'open',
        };
    }

    private function accountExists(TeamInvitation $invitation): bool
    {
        return User::query()
            ->where('email', $invitation->email)
            ->exists();
    }
}
