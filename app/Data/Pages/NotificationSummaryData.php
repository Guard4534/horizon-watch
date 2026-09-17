<?php

namespace App\Data\Pages;

use App\Data\Monitoring\NotificationSettingsData;
use Spatie\LaravelData\Data;

class NotificationSummaryData extends Data
{
    public function __construct(
        public int $recipientCount,
        public bool $webhookConfigured,
        public ?string $quietFrom,
        public ?string $quietTo,
        public ?int $repeatMinutes,
    ) {}

    public static function of(NotificationSettingsData $settings): self
    {
        return new self(
            recipientCount: count($settings->recipients),
            webhookConfigured: $settings->webhookUrl !== null && $settings->webhookUrl !== '',
            quietFrom: $settings->quietFrom,
            quietTo: $settings->quietTo,
            repeatMinutes: $settings->repeatMinutes,
        );
    }
}
