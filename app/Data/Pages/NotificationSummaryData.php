<?php

namespace App\Data\Pages;

use App\Data\Monitoring\NotificationSettingsData;
use Spatie\LaravelData\Data;

class NotificationSummaryData extends Data
{
    public function __construct(
        public int $recipientCount,
        public int $optedInMemberCount,
        public bool $webhookConfigured,
        public bool $reachesNobody,
        public ?string $quietFrom,
        public ?string $quietTo,
        public string $timezone,
        public ?int $repeatMinutes,
    ) {}

    public static function of(NotificationSettingsData $settings, int $optedInMemberCount): self
    {
        $recipientCount = count($settings->recipients);
        $webhookConfigured = $settings->webhookUrl !== null && $settings->webhookUrl !== '';

        return new self(
            recipientCount: $recipientCount,
            optedInMemberCount: $optedInMemberCount,
            webhookConfigured: $webhookConfigured,
            reachesNobody: $recipientCount === 0 && $optedInMemberCount === 0 && ! $webhookConfigured,
            quietFrom: $settings->quietFrom,
            quietTo: $settings->quietTo,
            timezone: $settings->timezone,
            repeatMinutes: $settings->repeatMinutes,
        );
    }
}
