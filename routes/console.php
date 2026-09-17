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

// A closure needs its name before withoutOverlapping(). The mutex expires
// after a minute rather than the default day, so a scheduler killed
// mid-run does not stop every poll until tomorrow.
Schedule::call(fn () => app(DispatchDuePolls::class)->handle())
    ->name('dispatch-due-polls')
    ->everyFifteenSeconds()
    ->withoutOverlapping(1);

// In the background, so a long prune (after a shorter retention, say) does
// not hold up the midnight polls, and guarded like any long job.
Schedule::command('monitoring:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
