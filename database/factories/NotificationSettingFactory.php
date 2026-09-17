<?php

namespace Database\Factories;

use App\Models\NotificationSetting;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'recipients' => [],
            'webhook_url' => null,
            'webhook_secret' => null,
            'quiet_from' => null,
            'quiet_to' => null,
            'timezone' => NotificationSetting::DEFAULT_TIMEZONE,
            'repeat_minutes' => NotificationSetting::DEFAULT_REPEAT_MINUTES,
        ];
    }

    public function withWebhook(string $url = 'https://hooks.example.com/horizon'): static
    {
        return $this->state(fn () => [
            'webhook_url' => $url,
            'webhook_secret' => fake()->regexify('[A-Za-z0-9]{40}'),
        ]);
    }

    public function quiet(string $from = '23:00', string $to = '07:00'): static
    {
        return $this->state(fn () => ['quiet_from' => $from, 'quiet_to' => $to]);
    }
}
