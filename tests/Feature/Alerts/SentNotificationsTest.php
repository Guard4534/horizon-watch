<?php

use App\Data\Monitoring\SentNotificationData;
use App\Enums\DeliveryStatus;
use App\Enums\MemberVisibility;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AlertTeam;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $this->production = Environment::factory()->for($application)->production()->create();
    $this->staging = Environment::factory()->for($application)->staging()->create();

    $productionAlert = Alert::factory()->for($this->production)->critical()->create();
    $stagingAlert = Alert::factory()->for($this->staging)->create();

    $log = fn (array $attributes) => AlertNotification::factory()->create(['team_id' => $this->team->id, 'alert_id' => null, ...$attributes]);

    $log(['alert_id' => $productionAlert->id, 'kind' => SentNotificationKind::CriticalAlert, 'target' => 'ops@example.com', 'sent_at' => now()->subMinutes(2)]);
    $log(['alert_id' => $productionAlert->id, 'kind' => SentNotificationKind::CriticalAlert, 'channel' => NotificationChannel::Webhook, 'target' => 'hooks.example.com', 'status' => DeliveryStatus::Failed, 'error' => 'http_5xx', 'sent_at' => now()->subMinutes(3)]);
    $log(['alert_id' => $stagingAlert->id, 'kind' => SentNotificationKind::Resolved, 'target' => 'dev@example.com', 'sent_at' => now()->subMinutes(5)]);
    $log(['kind' => SentNotificationKind::WarningDigest, 'target' => 'ops@example.com', 'environment_count' => 3, 'sent_at' => now()->subMinutes(15)]);
    $log(['kind' => SentNotificationKind::Test, 'channel' => NotificationChannel::Webhook, 'target' => 'hooks.example.com', 'sent_at' => now()->subMinutes(20)]);
});

function sentFor(Team $team, string $email, TeamRole $role, MemberVisibility $visibility = MemberVisibility::All, array $grants = []): array
{
    $user = AlertTeam::member($team, $email, $role, $visibility, grants: $grants);
    test()->actingAs($user);
    app()->forgetScopedInstances();

    return app(MonitoringRepository::class)->sentNotifications($team);
}

test('the panel lists the latest deliveries with their outcome', function () {
    $rows = sentFor($this->team, 'admin@example.com', TeamRole::Admin);

    expect(array_map(fn (SentNotificationData $row) => $row->toArray(), $rows))->toBe([
        ['channel' => 'mail', 'kind' => 'critical_alert', 'status' => 'sent', 'subject' => 'Alpha · production', 'target' => 'ops@example.com', 'minutesAgo' => 2],
        ['channel' => 'webhook', 'kind' => 'critical_alert', 'status' => 'failed', 'subject' => 'Alpha · production', 'target' => 'hooks.example.com', 'minutesAgo' => 3],
        ['channel' => 'mail', 'kind' => 'resolved', 'status' => 'sent', 'subject' => 'Alpha · staging', 'target' => 'dev@example.com', 'minutesAgo' => 5],
        ['channel' => 'mail', 'kind' => 'warning_digest', 'status' => 'sent', 'subject' => '3', 'target' => 'ops@example.com', 'minutesAgo' => 15],
        ['channel' => 'webhook', 'kind' => 'test', 'status' => 'sent', 'subject' => '', 'target' => 'hooks.example.com', 'minutesAgo' => 20],
    ]);
});

test('only the last six deliveries of the organization are listed', function () {
    AlertNotification::factory()->count(3)->create(['team_id' => $this->team->id, 'alert_id' => null, 'kind' => SentNotificationKind::Test, 'sent_at' => now()->subMinute()]);
    AlertNotification::factory()->create(['alert_id' => null, 'team_id' => Team::factory()->create()->id, 'kind' => SentNotificationKind::Test, 'sent_at' => now()]);

    $rows = sentFor($this->team, 'admin@example.com', TeamRole::Admin);

    expect($rows)->toHaveCount(6)
        ->and(array_column(array_map(fn ($row) => $row->toArray(), $rows), 'minutesAgo'))->toBe([1, 1, 1, 2, 3, 5]);
});

test('targets are shown only to who manages alert rules', function (TeamRole $role, bool $shown) {
    $rows = sentFor($this->team, 'someone@example.com', $role);

    expect($rows)->toHaveCount(5)
        ->and(collect($rows)->every(fn (SentNotificationData $row) => ($row->target !== null) === $shown))->toBeTrue();
})->with([
    'owner' => [TeamRole::Owner, true],
    'admin' => [TeamRole::Admin, true],
    'member' => [TeamRole::Member, false],
    'viewer' => [TeamRole::Viewer, false],
]);

test('a partial view lists only deliveries about watched environments', function (MemberVisibility $visibility, array $expected) {
    $rows = sentFor($this->team, 'partial@example.com', TeamRole::Member, $visibility, [$this->production]);

    expect(array_map(fn (SentNotificationData $row) => $row->subject, $rows))->toBe($expected);
})->with([
    'non production' => [MemberVisibility::NonProduction, ['Alpha · staging']],
    'manual on production' => [MemberVisibility::Manual, ['Alpha · production', 'Alpha · production']],
]);

test('the panel reads the log in a fixed number of queries', function () {
    AlertNotification::factory()->count(6)->create(['team_id' => $this->team->id, 'sent_at' => now()]);

    DB::enableQueryLog();
    sentFor($this->team, 'admin@example.com', TeamRole::Admin);
    $all = count(DB::getQueryLog());
    DB::flushQueryLog();

    sentFor($this->team, 'partial@example.com', TeamRole::Member, MemberVisibility::NonProduction);
    $partial = count(DB::getQueryLog());

    expect($all)->toBeLessThanOrEqual(12)
        ->and($partial)->toBeLessThanOrEqual(16);
});
