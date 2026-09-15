<?php

namespace App\Data\Monitoring;

use Spatie\LaravelData\Data;

class NotificationSettingsData extends Data
{
    public function __construct(
        /** @var array<int, string> */
        public array $recipients,
        public ?string $webhookUrl,
        public ?string $quietFrom,
        public ?string $quietTo,
        public ?int $repeatMinutes,
    ) {}
}
