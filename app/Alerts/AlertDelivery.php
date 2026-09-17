<?php

namespace App\Alerts;

use App\Enums\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\Bind;

#[Bind(NotificationDelivery::class)]
interface AlertDelivery
{
    public function sendTest(Team $team, NotificationChannel $channel, User $requestedBy): int;
}
