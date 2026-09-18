<?php

namespace App\Notifications\Alerts;

use App\Models\Alert;
use App\Models\Team;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class WarningDigestNotification extends Notification
{
    /**
     * @param  Collection<int, Alert>  $alerts
     */
    public function __construct(public Team $team, public Collection $alerts) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return AlertMail::message()
            ->subject($this->subject())
            ->markdown('mail.alerts.digest', [
                'color' => AlertMail::WARNING_COLOR,
                'headline' => $this->headline(),
                'sub' => $this->counts(),
                'rows' => $this->alerts->map(fn (Alert $alert) => [
                    'state' => $alert->resolved_at === null ? $this->stillOpen() : $this->resolved(),
                    'color' => $alert->resolved_at === null ? AlertMail::WARNING_COLOR : AlertMail::RESOLVED_COLOR,
                    'where' => AlertMail::where($alert),
                    'rule' => AlertMail::ruleLabel($alert->metric),
                    'value' => AlertMail::withUnit($alert->value ?? 0, $alert->unit),
                ])->values()->all(),
                'url' => AlertMail::wallUrl($this->team),
                'action' => $this->openPanel(),
            ]);
    }

    public function environmentCount(): int
    {
        return $this->alerts
            ->map(fn (Alert $alert) => $alert->environment_id ?? $alert->application_name."\0".$alert->environment_name)
            ->unique()
            ->count();
    }

    private function subject(): string
    {
        return __('[:digest] :organization — warnings', ['digest' => __('DIGEST'), 'organization' => AlertMail::organization($this->team)]);
    }

    private function headline(): string
    {
        return __('Warning digest');
    }

    private function counts(): string
    {
        return __('Warnings: :count · Environments: :environments', [
            'count' => AlertMail::number($this->alerts->count()),
            'environments' => AlertMail::number($this->environmentCount()),
        ]);
    }

    private function stillOpen(): string
    {
        return __('Still open');
    }

    private function resolved(): string
    {
        return __('Resolved');
    }

    private function openPanel(): string
    {
        return __('Open the panel');
    }
}
