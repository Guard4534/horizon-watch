<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $previousEmail, public string $newEmail) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your email address was changed'))
            ->line(__('The email address of your :app account was changed from :previous to :new.', [
                'app' => config()->string('app.name'),
                'previous' => $this->previousEmail,
                'new' => $this->newEmail,
            ]))
            ->line(__('If you did not make this change, tell whoever runs :app straight away: someone else may be using your account.', [
                'app' => config()->string('app.name'),
            ]));
    }
}
