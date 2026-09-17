<?php

namespace App\Data\Pages;

use App\Data\Monitoring\NotificationSettingsData;
use Spatie\LaravelData\Data;

/**
 * What every member may know about where alerts go: how many recipients and
 * whether a webhook exists, never the addresses or the URL (a webhook URL
 * often embeds a token).
 */
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
