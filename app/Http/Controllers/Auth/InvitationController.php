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
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the invitation: the front end decides what to render from
     * `state` (open, sign_in_required, expired, revoked, accepted,
     * wrong_account) and `authenticated`, never from a raw invitation
     * record. The organization/role/visibility/email are only ever sent
     * when the invitation is actionable by whoever is asking — every other
     * state must say nothing more ("senza dettagli", per the spec), and
     * this is an Inertia prop: it reaches the client whatever the page
     * renders.
     */
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
                // Named only for a manual selection: for the other two
                // visibilities the label already says what is included,
                // and listing the organization's environments to someone
                // who hasn't joined it yet would say too much.
                visibleEnvironmentNames: $canSeeDetails && $invitation->visibility === MemberVisibility::Manual
                    ? $invitation->environments->pluck('name')->all()
                    : [],
                state: $state,
                authenticated: $user !== null,
            ),
        ]);
    }

    /**
     * The guest path: public registration is disabled, so this is the only
     * way a new account gets created. The invitation's own token stands in
     * for both an invite and an email confirmation — hence
     * email_verified_at set right away. Account creation and acceptance
     * are locked together in RegisterInvitedUser: see there for why.
     */
    public function register(Request $request, string $code, AcceptInvitationData $data, RegisterInvitedUser $registerInvitedUser, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->findOrFail($code);

        abort_unless($this->stateFor($invitation, null) === 'open', 410);

        $user = $registerInvitedUser->handle($invitation, $data, $acceptInvitation);

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

        // Decline is gated the same as accept: only an open invitation can
        // be answered either way. Without this, a same-email user could
        // "decline" (delete) an invitation that's already accepted,
        // revoked or expired — nothing left to say no to at that point.
        abort_unless($this->stateFor($invitation, $user) === 'open', 410);

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
            ->with(['team', 'environments'])
            ->where('code', $code)
            ->firstOr(fn () => abort(404));
    }

    private function sameEmail(TeamInvitation $invitation, User $user): bool
    {
        return Str::lower($invitation->email) === Str::lower($user->email);
    }

    /**
     * Every state but "open" is a dead end for whoever is asking, and
     * show() nulls the invitation's details for all of them.
     */
    private function stateFor(TeamInvitation $invitation, ?User $user): string
    {
        return match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isRevoked() => 'revoked',
            $invitation->isExpired() => 'expired',
            $user !== null && ! $this->sameEmail($invitation, $user) => 'wrong_account',
            // Nobody is signed in but the address already has an account:
            // that person signs in and accepts, they don't register a
            // second account under the same address. Keeping it out of
            // "open" is what makes register() refuse it (410) instead of
            // letting RegisterInvitedUser's race guard answer a plain
            // form submission with a bare 409.
            $user === null && $this->accountExists($invitation) => 'sign_in_required',
            default => 'open',
        };
    }

    /**
     * Case-insensitively, like sameEmail(): an account that differs only
     * in case is still the account this person has to sign in to.
     */
    private function accountExists(TeamInvitation $invitation): bool
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($invitation->email)])
            ->exists();
    }
}
