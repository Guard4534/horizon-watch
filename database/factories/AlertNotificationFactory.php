<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Models\Alert;
use App\Models\AlertNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertNotification>
 */
class AlertNotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'team_id' => fn (array $attributes) => Alert::query()->whereKey($attributes['alert_id'])->firstOrFail()->team_id,
            'kind' => SentNotificationKind::CriticalAlert,
            'channel' => NotificationChannel::Mail,
            'target' => fake()->safeEmail(),
            'status' => DeliveryStatus::Sent,
            'error' => null,
            'sent_at' => now(),
        ];
    }
}
