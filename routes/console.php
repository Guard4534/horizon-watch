<?php

use App\Actions\Alerts\DispatchDueNotifications;
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
    ->withoutOverlapping(1)
    ->onOneServer();

Schedule::call(fn () => app(DispatchDueNotifications::class)->repeats())
    ->name('alert-repeats')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::call(fn () => app(DispatchDueNotifications::class)->digests())
    ->name('alert-digests')
    ->everyFifteenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('monitoring:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
