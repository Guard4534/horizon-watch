<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Alerts\HandleAlert;
use App\Actions\Alerts\MuteAlert;
use App\Actions\Alerts\UnmuteAlert;
use App\Alerts\EmailPreview;
use App\Data\Alerts\MuteAlertData;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AlertActionController extends Controller
{
    public function __construct(private readonly VisibleEnvironments $visible) {}

    public function mute(Request $request, Team $current_team, string $alert, MuteAlert $muteAlert): RedirectResponse
    {
        $found = $this->find($current_team, $request->user(), $alert);
        Gate::authorize('muteAlert', $current_team);

        $muteAlert->handle($found, $request->user(), MuteAlertData::from($request)->duration);

        return $this->done(__('Alert muted.'));
    }

    public function unmute(Request $request, Team $current_team, string $alert, UnmuteAlert $unmuteAlert): RedirectResponse
    {
        $found = $this->find($current_team, $request->user(), $alert);
        Gate::authorize('muteAlert', $current_team);

        $unmuteAlert->handle($found);

        return $this->done(__('Alert unmuted.'));
    }

    public function handle(Request $request, Team $current_team, string $alert, HandleAlert $handleAlert): RedirectResponse
    {
        $found = $this->find($current_team, $request->user(), $alert);
        Gate::authorize('handleAnomaly', $current_team);

        $handleAlert->handle($found, $request->user());

        return $this->done(__('Alert marked as handled.'));
    }

    public function preview(Request $request, Team $current_team, string $alert, EmailPreview $emailPreview): Response
    {
        $found = $this->find($current_team, $request->user(), $alert);
        Gate::authorize('manageAlertRules', $current_team);

        return response($emailPreview->render($found, app()->getLocale()), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ]);
    }

    private function find(Team $team, User $user, string $id): Alert
    {
        abort_unless(Str::isUuid($id), 404);

        $alert = Alert::query()->where('team_id', $team->id)->whereKey($id)->first();

        abort_if($alert === null, 404);
        abort_unless($this->visible->sees($team, $user, $alert->environment_id), 404);

        return $alert;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
