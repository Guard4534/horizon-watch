<?php

namespace App\Actions\Alerts;

use App\Models\Team;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegenerateWebhookSecret
{
    public function handle(Team $team): string
    {
        return DB::transaction(function () use ($team): string {
            $settings = $team->notificationSetting()->lockForUpdate()->first();

            if ($settings === null || $settings->webhook_url === null) {
                throw ValidationException::withMessages(['webhookUrl' => $this->missingUrlMessage()]);
            }

            try {
                $current = $settings->getAttribute('webhook_secret');
            } catch (DecryptException) {
                $current = null;
            }

            $previous = is_string($current) ? $current : null;

            $secret = self::newSecret();
            $settings->update([
                'webhook_secret' => $secret,
                'previous_webhook_secret' => $previous,
                'previous_webhook_secret_expires_at' => $previous === null ? null : now()->addHours(config()->integer('horizon-watch.notifications.previous_webhook_secret_hours')),
            ]);

            return $secret;
        });
    }

    public static function newSecret(): string
    {
        return Str::random(40);
    }

    private function missingUrlMessage(): string
    {
        return __('Save a webhook address first.');
    }
}
