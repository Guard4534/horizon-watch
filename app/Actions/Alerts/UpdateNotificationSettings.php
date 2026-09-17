<?php

namespace App\Actions\Alerts;

use App\Data\Alerts\NotificationSettingsInputData;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class UpdateNotificationSettings
{
    public function handle(Team $team, NotificationSettingsInputData $data): ?string
    {
        return DB::transaction(function () use ($team, $data): ?string {
            $settings = $team->notificationSetting()->lockForUpdate()->first()
                ?? $team->notificationSetting()->make();

            $url = $data->webhookUrl;
            $secret = null;

            $settings->fill([
                'recipients' => $data->uniqueRecipients(),
                'webhook_url' => $url,
                'quiet_from' => $data->quietFrom,
                'quiet_to' => $data->quietTo,
                'timezone' => $data->timezone,
                'repeat_minutes' => $data->repeatMinutes,
            ]);

            if ($url === null) {
                $settings->webhook_secret = null;
            } elseif ($settings->webhook_secret === null) {
                $secret = RegenerateWebhookSecret::newSecret();
                $settings->webhook_secret = $secret;
            }

            $settings->save();

            return $secret;
        });
    }
}
