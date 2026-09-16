<?php

namespace App\Notifications\Teams;

use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public TeamInvitationModel $invitation)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification. No sensitive data:
     * just the organization, the role, and a link — the invitation page
     * itself decides what the recipient can do with it.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->invitation->team;

        return (new MailMessage)
            ->subject(__('You have been invited to join :teamName', ['teamName' => $team->name]))
            ->line(__('You have been invited to join the :teamName organization as :role.', [
                'teamName' => $team->name,
                'role' => $this->invitation->role->label(),
            ]))
            ->action(
                __('Join the organization'),
                route('invitations.show', $this->invitation->code),
            )
            ->line(__('This invitation expires in 7 days.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'team_id' => $this->invitation->team_id,
            'team_name' => $this->invitation->team->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
