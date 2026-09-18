<?php

namespace App\Notifications\Alerts;

use App\Models\Team;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification
{
    public function __construct(public Team $team) {}

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
            ->markdown('mail.alerts.test', [
                'color' => AlertMail::RESOLVED_COLOR,
                'headline' => $this->headline(),
                'body' => $this->body(),
                'url' => AlertMail::wallUrl($this->team),
                'action' => $this->openPanel(),
            ]);
    }

    private function subject(): string
    {
        return __('[:test] :organization — test notification', ['test' => __('TEST'), 'organization' => AlertMail::organization($this->team)]);
    }

    private function headline(): string
    {
        return __('Test notification');
    }

    private function body(): string
    {
        return __('Alerts of :organization will reach this address.', ['organization' => AlertMail::organization($this->team)]);
    }

    private function openPanel(): string
    {
        return __('Open the panel');
    }
}
