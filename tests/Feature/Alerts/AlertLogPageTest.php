<?php

use App\Enums\AlertRuleMetric;
use App\Enums\DeliveryStatus;
use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\NotificationChannel;
use App\Enums\SentNotificationKind;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grants;
use Tests\Support\Readings;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00'));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value, 'visibility' => MemberVisibility::All->value]);
    $this->admin->switchTeam($this->team);

    $this->billing = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $this->shop = Application::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->production = Environment::factory()->for($this->billing)->production()->create();
    $this->staging = Environment::factory()->for($this->billing)->staging()->create();
    $this->shopProduction = Environment::factory()->for($this->shop)->production()->create();

    $this->memberAs = function (TeamRole $role, MemberVisibility $visibility = MemberVisibility::All): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->page = function (array $query = [], ?User $as = null): array {
        $page = null;

        $this->actingAs($as ?? $this->admin)
            ->get(route('alerts.index', ['current_team' => $this->team->slug, ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $inertia) use (&$page) {
                $inertia->component('monitoring/alerts/Index');
                $page = $inertia->toArray()['props']['page'];
            });

        return $page;
    };
});

test('the open tab lists the open alerts with their details, and the counts of every tab', function () {
    Readings::record($this->production, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: [
        'pending' => 2600,
        'max_wait_seconds' => 40,
        'node_count' => 3,
    ]);
    Alert::factory()->for($this->staging)->create(['muted_indefinitely' => true, 'muted_by' => $this->admin->id]);
    Alert::factory()->for($this->staging)->resolved()->create(['metric' => AlertRuleMetric::QueueMaxWait]);

    $page = ($this->page)();
    $alert = $page['alerts'][0];

    expect($page['state'])->toBe('open')
        ->and($page['counts'])->toBe(['open' => 1, 'muted' => 1, 'resolved' => 1])
        ->and([$page['page'], $page['total'], $page['perPage'], $page['application']])->toBe([1, 1, 50, null])
        ->and(array_column($page['applications'], 'id'))->toBe([$this->billing->slug, $this->shop->slug])
        ->and($page['alerts'])->toHaveCount(1)
        ->and($alert)->toMatchArray([
            'state' => 'open',
            'severity' => 'warning',
            'metric' => 'queue.pending',
            'unit' => 'job',
            'environmentId' => $this->production->slug,
            'applicationName' => 'Billing',
            'environmentName' => 'production',
            'color' => 'prod',
            'environmentStatus' => 'degraded',
            'minutesAgo' => 0,
            'resolvedMinutesAgo' => null,
            'mutedUntil' => null,
            'mutedUntilResolved' => false,
            'mutedBy' => null,
            'handledBy' => null,
            'handledMinutesAgo' => null,
            'channels' => [],
            'canMute' => true,
            'canHandle' => true,
        ])
        ->and((float) $alert['threshold'])->toBe(2000.0)
        ->and((float) $alert['value'])->toBe(2600.0)
        ->and($alert)->not->toHaveKeys(['nodeCount', 'pending', 'maxWaitSeconds']);
});

test('the muted tab says until when and who muted', function () {
    $muter = User::factory()->create(['name' => 'Mia Muter']);
    Alert::factory()->for($this->production)->create(['muted_until' => now()->addHours(4), 'muted_by' => $muter->id]);
    Alert::factory()->for($this->staging)->create(['muted_indefinitely' => true, 'muted_by' => null, 'opened_at' => now()->subHour()]);

    $alerts = ($this->page)(['state' => 'muted'])['alerts'];

    expect(array_map(fn (array $alert) => [$alert['state'], $alert['mutedUntil'], $alert['mutedUntilResolved'], $alert['mutedBy']], $alerts))->toBe([
        ['muted', '2026-09-18T14:00:00+00:00', false, ['name' => 'Mia Muter']],
        ['muted', null, true, null],
    ]);
});

test('an alert taken in charge says by whom and since when, and an expired mute is forgotten', function () {
    $handler = User::factory()->create(['name' => 'Hal Handler']);
    Alert::factory()->for($this->production)->create([
        'handled_at' => now()->subMinutes(12),
        'handled_by' => $handler->id,
        'muted_until' => now()->subMinute(),
        'muted_by' => $this->admin->id,
    ]);

    $alert = ($this->page)()['alerts'][0];

    expect($alert['handledBy'])->toBe(['name' => 'Hal Handler'])
        ->and($alert['handledMinutesAgo'])->toBe(12)
        ->and($alert['mutedBy'])->toBeNull()
        ->and($alert['mutedUntil'])->toBeNull();
});

test('the resolved tab lists the newest resolutions within the retention, and none may be acted on', function () {
    config(['horizon-watch.alert_retention_days' => 90]);
    $older = Alert::factory()->for($this->production)->create(['resolved_at' => now()->subDays(2), 'opened_at' => now()->subDays(3)]);
    $newer = Alert::factory()->for($this->staging)->create(['resolved_at' => now()->subMinutes(5), 'handled_at' => now()->subHour(), 'handled_by' => $this->admin->id]);
    Alert::factory()->for($this->production)->create(['resolved_at' => now()->subDays(91)]);

    $page = ($this->page)(['state' => 'resolved']);

    expect(array_column($page['alerts'], 'id'))->toBe([$newer->id, $older->id])
        ->and($page['counts']['resolved'])->toBe(2)
        ->and($page['total'])->toBe(2)
        ->and($page['alerts'][0])->toMatchArray([
            'state' => 'resolved',
            'resolvedMinutesAgo' => 5,
            'handledBy' => ['name' => 'Ada Admin'],
            'canMute' => false,
            'canHandle' => false,
        ])
        ->and($page['alerts'][1]['minutesAgo'])->toBe(4320);
});

test('the channels are the ones with at least one delivery that went out', function () {
    $alert = Alert::factory()->for($this->production)->critical()->create();
    $quiet = Alert::factory()->for($this->staging)->create();
    $log = fn (Alert $alert, NotificationChannel $channel, DeliveryStatus $status) => AlertNotification::factory()->for($alert)->create([
        'team_id' => $this->team->id,
        'kind' => SentNotificationKind::CriticalAlert,
        'channel' => $channel,
        'status' => $status,
    ]);
    $log($alert, NotificationChannel::Webhook, DeliveryStatus::Sent);
    $log($alert, NotificationChannel::Mail, DeliveryStatus::Sent);
    $log($alert, NotificationChannel::Mail, DeliveryStatus::Sent);
    $log($quiet, NotificationChannel::Webhook, DeliveryStatus::Failed);

    $alerts = collect(($this->page)()['alerts'])->keyBy('id');

    expect($alerts[$alert->id]['channels'])->toBe(['mail', 'webhook'])
        ->and($alerts[$quiet->id]['channels'])->toBe([]);
});

test('a viewer sees the alerts but may not act on them', function () {
    Alert::factory()->for($this->production)->create();

    $alert = ($this->page)([], ($this->memberAs)(TeamRole::Viewer))['alerts'][0];

    expect([$alert['canMute'], $alert['canHandle']])->toBe([false, false]);
});

test('a member may act on the alerts they see', function () {
    Alert::factory()->for($this->production)->create();

    $alert = ($this->page)([], ($this->memberAs)(TeamRole::Member))['alerts'][0];

    expect([$alert['canMute'], $alert['canHandle']])->toBe([true, true]);
});

test('a member limited to non-production neither lists nor counts production alerts', function (string $state) {
    $attributes = match ($state) {
        'open' => [],
        'muted' => ['muted_indefinitely' => true],
        'resolved' => ['resolved_at' => now()->subMinute()],
    };
    Alert::factory()->for($this->production)->create($attributes);
    $staging = Alert::factory()->for($this->staging)->create($attributes);

    $page = ($this->page)(['state' => $state], ($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction));

    expect(array_column($page['alerts'], 'id'))->toBe([$staging->id])
        ->and($page['counts'][$state])->toBe(1)
        ->and($page['total'])->toBe(1);
})->with(['open', 'muted', 'resolved']);

test('a manual member only gets the alerts of the environments granted to them', function () {
    $member = ($this->memberAs)(TeamRole::Member, MemberVisibility::Manual);
    Grants::give($member->id, $this->shopProduction->id);
    Alert::factory()->for($this->production)->create();
    $granted = Alert::factory()->for($this->shopProduction)->create();

    expect(array_column(($this->page)([], $member)['alerts'], 'id'))->toBe([$granted->id]);
});

test('the resolved alerts of a deleted environment are kept for those who see every environment', function () {
    $orphan = Alert::factory()->withoutEnvironment()->resolved()->create([
        'team_id' => $this->team->id,
        'application_name' => 'Legacy',
        'environment_name' => 'old-production',
        'environment_color' => EnvironmentColor::Prod,
    ]);
    Alert::factory()->withoutEnvironment()->resolved()->create([
        'team_id' => Team::factory()->create()->id,
        'application_name' => 'Elsewhere',
        'environment_name' => 'production',
        'environment_color' => EnvironmentColor::Prod,
    ]);

    $admin = ($this->page)(['state' => 'resolved']);
    $limited = ($this->page)(['state' => 'resolved'], ($this->memberAs)(TeamRole::Admin, MemberVisibility::NonProduction));

    expect(array_column($admin['alerts'], 'id'))->toBe([$orphan->id])
        ->and($admin['alerts'][0])->toMatchArray([
            'environmentId' => null,
            'applicationName' => 'Legacy',
            'environmentName' => 'old-production',
            'environmentStatus' => null,
            'collectionPaused' => false,
        ])
        ->and($admin['counts']['resolved'])->toBe(1)
        ->and($limited['alerts'])->toBe([])
        ->and($limited['counts']['resolved'])->toBe(0);
});

test('the alerts of another organization never appear', function () {
    Alert::factory()->for(Environment::factory()->for(Application::factory()))->create();

    $page = ($this->page)();

    expect($page['alerts'])->toBe([])
        ->and($page['counts'])->toBe(['open' => 0, 'muted' => 0, 'resolved' => 0]);
});

test('the application filter keeps the alerts of that application', function () {
    $billing = Alert::factory()->for($this->production)->create();
    Alert::factory()->for($this->shopProduction)->create();

    $page = ($this->page)(['application' => $this->billing->slug]);

    expect(array_column($page['alerts'], 'id'))->toBe([$billing->id])
        ->and($page['application'])->toBe($this->billing->slug)
        ->and($page['total'])->toBe(1)
        ->and($page['counts']['open'])->toBe(2);
});

test('an application filter that matches nothing the viewer sees lists nothing', function (string $slug) {
    Alert::factory()->for($this->production)->create();

    $member = ($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction);

    expect(($this->page)(['application' => $slug], $member)['alerts'])->toBe([]);
})->with(['unknown', 'shop']);

test('the pages hold fifty alerts each, critical first then newest', function () {
    foreach (range(1, 55) as $minute) {
        Alert::factory()->for(Environment::factory()->for($this->billing)->create(['name' => "worker-{$minute}"]))->create([
            'opened_at' => now()->subMinutes($minute),
        ]);
    }
    $critical = Alert::factory()->for($this->production)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'opened_at' => now()->subDay(),
    ]);

    $first = ($this->page)();
    $second = ($this->page)(['page' => 2]);
    $beyond = ($this->page)(['page' => 3]);

    expect($first['alerts'])->toHaveCount(50)
        ->and($first['alerts'][0]['id'])->toBe($critical->id)
        ->and(array_column(array_slice($first['alerts'], 1, 3), 'minutesAgo'))->toBe([1, 2, 3])
        ->and([$first['page'], $first['total'], $first['perPage']])->toBe([1, 56, 50])
        ->and($second['page'])->toBe(2)
        ->and(array_column($second['alerts'], 'minutesAgo'))->toBe([50, 51, 52, 53, 54, 55])
        ->and($beyond['alerts'])->toBe([])
        ->and(($this->page)(['page' => 0])['page'])->toBe(1);
});

test('each tab reads its alerts with the same number of queries for one alert or thirty', function (string $state) {
    $queriesFor = function (int $count) use ($state): int {
        Alert::query()->delete();

        foreach (range(1, $count) as $index) {
            $environment = Environment::factory()->for($this->billing)->create(['name' => "worker-{$count}-{$index}"]);
            Readings::record($environment);
            $user = User::factory()->create();
            $alert = Alert::factory()->for($environment)->create([
                'handled_at' => now(),
                'handled_by' => $user->id,
                'muted_until' => $state === 'muted' ? now()->addHour() : now()->subHour(),
                'muted_by' => $user->id,
                'resolved_at' => $state === 'resolved' ? now() : null,
            ]);
            AlertNotification::factory()->for($alert)->create(['team_id' => $this->team->id, 'status' => DeliveryStatus::Sent]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = ($this->page)(['state' => $state]);
        DB::disableQueryLog();

        expect($page['alerts'])->toHaveCount($count)
            ->and(collect($page['alerts'])->every(fn (array $alert) => $alert['handledBy'] !== null && $alert['channels'] === ['mail']))->toBeTrue();

        return count(DB::getQueryLog());
    };

    $one = $queriesFor(1);
    $thirty = $queriesFor(30);

    expect($thirty)->toBe($one)
        ->and($one)->toBeLessThan(40);
})->with(['open', 'muted', 'resolved']);

test('the application page lists its open alerts and its five latest resolutions', function () {
    $open = Alert::factory()->for($this->production)->critical()->create(['metric' => AlertRuleMetric::EndpointUnreachable]);
    Alert::factory()->for($this->production)->create(['muted_indefinitely' => true]);
    Alert::factory()->for($this->shopProduction)->create();
    $resolved = collect(range(1, 6))->map(fn (int $hours) => Alert::factory()->for($this->staging)->create([
        'resolved_at' => now()->subHours($hours),
    ]));
    Alert::factory()->for($this->shopProduction)->create(['resolved_at' => now()->subMinute()]);

    $this->actingAs($this->admin)
        ->get(route('applications.show', ['current_team' => $this->team->slug, 'application' => $this->billing->slug]))
        ->assertInertia(fn (Assert $page) => $page->where(
            'page.recentAlerts',
            fn ($alerts) => collect($alerts)->pluck('id')->all() === [$open->id, ...$resolved->take(5)->pluck('id')->all()],
        ));
});

test('the environment page carries its most urgent open alert', function () {
    Alert::factory()->for($this->production)->create(['opened_at' => now()->subMinute()]);
    $critical = Alert::factory()->for($this->production)->critical()->create([
        'metric' => AlertRuleMetric::EndpointUnreachable,
        'opened_at' => now()->subHour(),
    ]);
    Alert::factory()->for($this->staging)->critical()->create(['metric' => AlertRuleMetric::EndpointUnreachable]);

    $this->actingAs($this->admin)
        ->get(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $this->production->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.openAlert.id', $critical->id)
            ->where('page.openAlert.canMute', true));
});

test('the sidebar badge counts the open alerts the viewer sees, muted ones aside', function () {
    Alert::factory()->for($this->production)->create();
    Alert::factory()->for($this->staging)->create();
    Alert::factory()->for($this->staging)->create(['metric' => AlertRuleMetric::QueueMaxWait, 'muted_indefinitely' => true]);
    Alert::factory()->for($this->staging)->resolved()->create();

    $this->actingAs($this->admin)
        ->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('openAlertCount', 2)
            ->where('page.kpis.openAnomalies', 2)
            ->has('page.anomalies', 2));

    $this->actingAs(($this->memberAs)(TeamRole::Viewer, MemberVisibility::NonProduction))
        ->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('openAlertCount', 1));
});

test('the sidebar badge always equals the open tab, whoever looks', function () {
    Alert::factory()->for($this->production)->create();
    Alert::factory()->for($this->staging)->create(['muted_until' => now()->subMinute()]);
    Alert::factory()->for($this->staging)->create(['metric' => AlertRuleMetric::QueueMaxWait, 'muted_until' => now()]);
    Alert::factory()->for($this->shopProduction)->create(['muted_until' => now()->addHour()]);
    Alert::factory()->for($this->shopProduction)->create(['metric' => AlertRuleMetric::QueueMaxWait, 'muted_indefinitely' => true]);
    Alert::factory()->for($this->production)->resolved()->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    $orphan = Alert::factory()->for($this->production)->create(['metric' => AlertRuleMetric::JobRuntime]);
    $orphan->forceFill(['environment_id' => null])->save();
    Alert::factory()->for(Environment::factory())->create();

    foreach ([$this->admin, ($this->memberAs)(TeamRole::Viewer, MemberVisibility::NonProduction)] as $viewer) {
        $this->actingAs($viewer)
            ->get(route('alerts.index', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('openAlertCount', fn (int $count) => $count === count($page->toArray()['props']['page']['alerts'])
                    && $count === $page->toArray()['props']['page']['counts']['open']));
    }

    expect(($this->page)()['counts']['open'])->toBe(3);
});

test('the sidebar badge is one count, with no alert built for it', function () {
    $queries = function (int $open): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)
            ->get(route('members.index', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('openAlertCount', $open));

        return array_column(DB::getQueryLog(), 'query');
    };

    $none = $queries(0);

    Alert::factory()->for($this->staging)->count(3)->sequence(
        ['metric' => AlertRuleMetric::QueuePending, 'muted_by' => $this->admin->id],
        ['metric' => AlertRuleMetric::QueueMaxWait, 'handled_by' => $this->admin->id],
        ['metric' => AlertRuleMetric::JobRuntime],
    )->create();
    AlertNotification::factory()->for(Alert::query()->first())->create();

    $some = $queries(3);

    expect(count($some))->toBe(count($none))
        ->and(array_filter($some, fn (string $sql) => str_contains($sql, 'alert_notifications') || str_contains($sql, 'environment_states')))->toBe([])
        ->and(array_filter($some, fn (string $sql) => str_contains($sql, 'from "alerts"')))->toHaveCount(1);
});

test('the application filter offers only the applications with an environment the viewer watches', function () {
    Application::factory()->for($this->team)->create(['name' => 'Empty']);

    $member = ($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction);

    expect(array_column(($this->page)()['applications'], 'id'))->toBe([$this->billing->slug, $this->shop->slug])
        ->and(array_column(($this->page)([], $member)['applications'], 'name'))->toBe(['Billing']);
});

test('an alert carries the longest job and the queues without workers it was opened for', function () {
    Alert::factory()->for($this->production)->create([
        'metric' => AlertRuleMetric::JobRuntime,
        'unit' => 's',
        'value' => 300,
        'detail' => ['job' => 'App\Jobs\BuildReport', 'queue' => 'reports', 'seconds' => 300],
    ]);
    Alert::factory()->for($this->staging)->create([
        'metric' => AlertRuleMetric::WorkersMissing,
        'unit' => '',
        'value' => 2,
        'detail' => ['queues' => ['default', 'emails']],
    ]);
    Alert::factory()->for($this->shopProduction)->create();

    $alerts = collect(($this->page)()['alerts'])->keyBy('metric');

    expect($alerts['job.runtime'])->toMatchArray(['longestJob' => 'App\Jobs\BuildReport', 'longestJobQueue' => 'reports', 'queuesWithoutWorkers' => []])
        ->and($alerts['workers.missing'])->toMatchArray(['longestJob' => null, 'longestJobQueue' => null, 'queuesWithoutWorkers' => ['default', 'emails']])
        ->and($alerts['queue.pending'])->toMatchArray(['longestJob' => null, 'longestJobQueue' => null, 'queuesWithoutWorkers' => []]);
});

test('a detail of an unexpected shape is left out', function (array $detail, array $queues) {
    Alert::factory()->for($this->production)->create([
        'metric' => AlertRuleMetric::WorkersMissing,
        'detail' => $detail,
    ]);

    expect(($this->page)()['alerts'][0])->toMatchArray(['longestJob' => null, 'longestJobQueue' => null, 'queuesWithoutWorkers' => $queues]);
})->with([
    'scalars where lists belong' => [['queues' => 'default', 'job' => ['nested'], 'queue' => 7], []],
    'foreign items in the queue list' => [['queues' => ['default', ['nested'], 7, 'emails']], ['default', 'emails']],
]);

test('an alert still open on an environment whose collection is paused says so', function () {
    $this->production->update(['polling_enabled' => false]);
    Alert::factory()->for($this->production)->create();
    Alert::factory()->for($this->production)->resolved()->create(['metric' => AlertRuleMetric::QueueMaxWait]);
    Alert::factory()->for($this->staging)->create();

    $open = collect(($this->page)()['alerts'])->keyBy('environmentName');

    expect($open['production']['collectionPaused'])->toBeTrue()
        ->and($open['staging']['collectionPaused'])->toBeFalse()
        ->and(($this->page)(['state' => 'resolved'])['alerts'][0]['collectionPaused'])->toBeFalse();
});
