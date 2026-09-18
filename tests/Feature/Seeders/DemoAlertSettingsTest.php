<?php

use App\Models\AlertRule;
use App\Models\NotificationSetting;
use App\Models\Team;
use Database\Seeders\DatabaseSeeder;

test('the demo organization carries real rule overrides and notification settings', function () {
    config()->set('horizon-watch.demo_horizon_url', null);

    $this->seed(DatabaseSeeder::class);

    $team = Team::query()->sole();

    expect(AlertRule::query()->where('team_id', $team->id)->orderBy('id')->get()->map(fn (AlertRule $rule) => [
        $rule->scope, $rule->metric->value, $rule->threshold, $rule->severity, $rule->notify_email, $rule->enabled,
    ])->all())->toBe([
        ['production', 'horizon.master_inactive', 2.0, null, null, null],
        ['production', 'queue.pending', 5000.0, null, null, null],
        ['production', 'queue.max_wait', 90.0, null, null, null],
        ['preprod', 'horizon.master_inactive', 10.0, null, null, null],
        ['worker-batch', 'job.runtime', 900.0, null, null, null],
        ['worker-batch', 'workers.missing', 2.0, null, null, null],
    ]);

    $settings = NotificationSetting::query()->sole();
    expect($settings->team_id)->toBe($team->id)
        ->and($settings->recipients)->toBe(['ops@example.com', 'oncall@example.com'])
        ->and($settings->webhook_url)->toBe('https://hooks.example.com/horizon')
        ->and($settings->webhook_secret)->toHaveLength(40)
        ->and($settings->getRawOriginal('quiet_from'))->toBe('23:00:00')
        ->and($settings->getRawOriginal('quiet_to'))->toBe('07:00:00')
        ->and($settings->repeat_minutes)->toBe(30);
});
