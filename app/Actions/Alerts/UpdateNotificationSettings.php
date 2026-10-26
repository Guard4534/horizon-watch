<?php

namespace App\Actions\Alerts;

use App\Data\Alerts\NotificationSettingsInputData;
use App\Models\NotificationSetting;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateNotificationSettings
{
    public function handle(Team $team, NotificationSettingsInputData $data): ?string
    {
        return DB::transaction(function () use ($team, $data): ?string {
            $this->ensureRow($team);

            $settings = $team->notificationSetting()->lockForUpdate()->firstOrFail();

            $url = $data->webhookUrl;
            $secret = null;
            $movedHost = $url !== null && self::host($url) !== self::host($settings->webhook_url);

            $settings->fill([
                'recipients' => $data->uniqueRecipients(),
                'webhook_url' => $url,
                'quiet_from' => $data->quietFrom,
                'quiet_to' => $data->quietTo,
                'timezone' => $data->timezone,
                'repeat_minutes' => $data->repeatMinutes,
            ]);

            if ($url === null || $movedHost || $settings->getRawOriginal('webhook_secret') === null) {
                $secret = $url === null ? null : RegenerateWebhookSecret::newSecret();
                $settings->webhook_secret = $secret;
                $settings->previous_webhook_secret = null;
                $settings->previous_webhook_secret_expires_at = null;
            }

            $settings->save();

            return $secret;
        });
    }

    private static function host(?string $url): string
    {
        return Str::lower((string) parse_url((string) $url, PHP_URL_HOST));
    }

    private function ensureRow(Team $team): void
    {
        $model = new NotificationSetting;
        $timestamp = $model->freshTimestamp();

        DB::table($model->getTable())->insertOrIgnore($model->forceFill([
            'team_id' => $team->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->getAttributes());
    }
}
