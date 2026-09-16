<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\AcceptInvitation;
use App\Actions\Teams\DeclineInvitation;
use App\Data\Pages\InvitationPageData;
use App\Data\Teams\AcceptInvitationData;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the invitation: the front end decides what to render from
     * `state` (open, expired, revoked, accepted, wrong_account) and
     * `authenticated`, never from a raw invitation record.
     */
    public function show(string $code): Response
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();

        return Inertia::render('auth/Invitation', [
            'page' => new InvitationPageData(
                organizationName: $invitation->team->name,
                roleLabel: $invitation->role->label(),
                visibilityLabel: $invitation->visibility->label(),
                email: $invitation->email,
                state: $this->stateFor($invitation, $user),
                authenticated: $user !== null,
            ),
        ]);
    }

    /**
     * The guest path: public registration is disabled, so this is the only
     * way a new account gets created. The invitation's own token stands in
     * for both an invite and an email confirmation — hence
     * email_verified_at set right away.
     */
    public function register(Request $request, string $code, AcceptInvitationData $data, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);

        abort_unless($this->stateFor($invitation, null) === 'open', 410);

        // An account for this email already exists (created outside this
        // invitation, e.g. it owns another organization): that person
        // should log in and accept, not register a second account under
        // the same address — blocked by the unique constraint anyway, but
        // this fails clearly instead of a raw query exception.
        abort_if(User::where('email', $invitation->email)->exists(), 409);

        $user = DB::transaction(function () use ($invitation, $data, $acceptInvitation) {
            $user = User::create([
                'name' => $data->name,
                'email' => $invitation->email,
                'password' => $data->password,
                'email_verified_at' => now(),
            ]);

            $acceptInvitation->handle($invitation, $user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('wall', ['current_team' => $user->currentTeam?->slug]);
    }

    /**
     * The authenticated path, same email as the invitation.
     */
    public function accept(string $code, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();

        abort_if($user === null, 403);
        abort_unless($this->sameEmail($invitation, $user), 403);
        abort_unless($this->stateFor($invitation, $user) === 'open', 410);

        $acceptInvitation->handle($invitation, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('wall', ['current_team' => $user->currentTeam?->slug]);
    }

    /**
     * The authenticated path, "no thanks": removes the invitation, same as
     * a revoke from the admin side would leave it — nothing to keep.
     */
    public function decline(string $code, DeclineInvitation $declineInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);
        $user = Auth::user();

        abort_if($user === null, 403);
        abort_unless($this->sameEmail($invitation, $user), 403);

        $declineInvitation->handle($invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('wall');
    }

    /**
     * Looked up by code directly (not route-model-bound): expired, revoked
     * and accepted invitations are still real rows that must render their
     * own state, only a genuinely unknown code is a 404.
     */
    private function findOrFail(string $code): TeamInvitation
    {
        return TeamInvitation::query()
            ->with('team')
            ->where('code', $code)
            ->firstOr(fn () => abort(404));
    }

    private function sameEmail(TeamInvitation $invitation, User $user): bool
    {
        return Str::lower($invitation->email) === Str::lower($user->email);
    }

    private function stateFor(TeamInvitation $invitation, ?User $user): string
    {
        return match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isRevoked() => 'revoked',
            $invitation->isExpired() => 'expired',
            $user !== null && ! $this->sameEmail($invitation, $user) => 'wrong_account',
            default => 'open',
        };
    }
}
