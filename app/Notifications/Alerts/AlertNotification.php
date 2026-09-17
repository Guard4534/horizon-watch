<?php

namespace App\Notifications\Alerts;

use App\Models\Alert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertNotification extends Notification
{
    public function __construct(public Alert $alert, public bool $repeated = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alert = $this->alert;

        return AlertMail::message()
            ->subject(AlertMail::subject(AlertMail::severityTag($alert->severity), $alert))
            ->markdown('mail.alerts.alert', [
                'color' => AlertMail::severityColor($alert->severity),
                'headline' => AlertMail::headline($alert),
                'sub' => AlertMail::detectedAt($alert),
                'detail' => AlertMail::detail($alert),
                'rows' => AlertMail::rows($alert),
                'note' => $this->repeated ? $this->repeatNote() : null,
                'url' => AlertMail::url($alert),
                'action' => $this->openPanel(),
            ]);
    }

    private function repeatNote(): string
    {
        return __('Still open: this email repeats until the alert clears, is muted or is handled.');
    }

    private function openPanel(): string
    {
        return __('Open the panel');
    }
}
