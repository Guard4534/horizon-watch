<?php

use App\Actions\Monitoring\DispatchDuePolls;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::call(fn () => app(DispatchDuePolls::class)->handle())
    ->name('dispatch-due-polls')
    ->everyFifteenSeconds()
    ->withoutOverlapping(1);

Schedule::command('monitoring:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
