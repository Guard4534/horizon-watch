<?php

namespace App\Notifications\Alerts;

use App\Models\Alert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResolvedNotification extends Notification
{
    public function __construct(public Alert $alert) {}

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
            ->subject(AlertMail::subject($this->tag(), $alert))
            ->markdown('mail.alerts.alert', [
                'color' => AlertMail::RESOLVED_COLOR,
                'headline' => $this->headline(),
                'sub' => AlertMail::resolvedAt($alert),
                'detail' => null,
                'rows' => AlertMail::rows($alert),
                'note' => null,
                'url' => AlertMail::url($alert),
                'action' => $this->openPanel(),
            ]);
    }

    private function tag(): string
    {
        return __('RESOLVED');
    }

    private function headline(): string
    {
        return __('Back within the threshold: :rule', ['rule' => AlertMail::ruleLabel($this->alert->metric)]);
    }

    private function openPanel(): string
    {
        return __('Open the panel');
    }
}
