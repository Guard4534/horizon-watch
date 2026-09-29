<?php

namespace App\Actions\Alerts;

use App\Alerts\NotificationDelivery;
use App\Enums\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SendTestNotification
{
    public function __construct(private NotificationDelivery $delivery) {}

    public function handle(Team $team, NotificationChannel $channel, User $requestedBy): int
    {
        $count = $this->delivery->sendTest($team, $channel, $requestedBy);

        if ($count === 0) {
            throw ValidationException::withMessages(['channel' => $this->nowhereMessage()]);
        }

        return $count;
    }

    private function nowhereMessage(): string
    {
        return __('There is nowhere to send a test yet: save the notification settings first.');
    }
}
