<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Alerts\RegenerateWebhookSecret;
use App\Actions\Alerts\SendTestNotification;
use App\Actions\Alerts\UpdateNotificationSettings;
use App\Data\Alerts\NotificationSettingsInputData;
use App\Data\Alerts\TestNotificationInputData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\AlertRulesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertSettingsController extends Controller
{
    public function update(Request $request, Team $current_team, NotificationSettingsInputData $data, UpdateNotificationSettings $updateNotificationSettings): RedirectResponse
    {
        $secret = $updateNotificationSettings->handle($current_team, $data);

        if ($secret !== null) {
            AlertRulesQuery::flashNewWebhookSecret($current_team, $request->session(), $secret);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return back();
    }

    public function regenerateSecret(Request $request, Team $current_team, RegenerateWebhookSecret $regenerateWebhookSecret): RedirectResponse
    {
        AlertRulesQuery::flashNewWebhookSecret($current_team, $request->session(), $regenerateWebhookSecret->handle($current_team));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New webhook secret generated.')]);

        return back();
    }

    public function test(Request $request, Team $current_team, TestNotificationInputData $data, SendTestNotification $sendTestNotification): RedirectResponse
    {
        $count = $sendTestNotification->handle($current_team, $data->channel, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1
            ? __('Test sent to one destination.')
            : __('Test sent to :count destinations.', ['count' => $count])]);

        return back();
    }
}
