<?php

use App\Enums\AlertSeverity;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\AlertRule;
use App\Models\Application;
use App\Models\Environment;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
    Environment::factory()->for(Application::factory()->for($this->team))->production()->create();

    $this->memberOf = function (TeamRole $role): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->pageOf = function (string $scope): array {
        $props = null;

        $this->get(route('alert-rules.index', ['current_team' => $this->team->slug, 'scope' => $scope]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props']['page'];
            });

        return $props;
    };
});

test('an environment scope carries the organization values it inherits, the organization scope none', function (TeamRole $role) {
    $this->team->alertRules()->create([
        'scope' => AlertRule::ORGANIZATION,
        'metric' => 'queue.pending',
        'threshold' => 3000,
        'severity' => AlertSeverity::Critical,
        'notify_email' => false,
        'enabled' => true,
    ]);
    $this->team->alertRules()->create([
        'scope' => 'production',
        'metric' => 'queue.pending',
        'threshold' => 9000,
        'severity' => AlertSeverity::Warning,
        'notify_email' => true,
        'enabled' => false,
    ]);

    $this->actingAs(($this->memberOf)($role));

    expect(($this->pageOf)('organization')['organizationRules'])->toBe([]);

    $page = ($this->pageOf)('production');
    $inherited = collect($page['organizationRules'])->keyBy('metric');
    $own = collect($page['rules'])->keyBy('metric');

    expect($inherited->keys()->all())->toBe($own->keys()->all())
        ->and((float) $own['queue.pending']['threshold'])->toBe(9000.0)
        ->and((float) $inherited['queue.pending']['threshold'])->toBe(3000.0)
        ->and($inherited['queue.pending'])->toMatchArray([
            'severity' => 'critical',
            'notifyByEmail' => false,
            'enabled' => true,
            'origin' => 'organization',
        ])
        ->and((float) $inherited['queue.max_wait']['threshold'])->toBe(60.0);
})->with([
    'admin' => TeamRole::Admin,
    'viewer' => TeamRole::Viewer,
]);

test('the repeat choices and the recipient limit come from the configuration', function () {
    config([
        'horizon-watch.notifications.repeat_minutes' => [5, 45],
        'horizon-watch.notifications.max_recipients' => 3,
    ]);

    $this->actingAs(($this->memberOf)(TeamRole::Admin));

    expect(($this->pageOf)('organization'))
        ->repeatChoices->toBe([5, 45])
        ->maxRecipients->toBe(3);
});

test('the default configuration offers 15, 30 and 60 minutes and twenty recipients', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Member));

    expect(($this->pageOf)('organization'))
        ->repeatChoices->toBe([15, 30, 60])
        ->maxRecipients->toBe(20);
});

test('the me page shows the quiet hours of the organization', function () {
    $user = ($this->memberOf)(TeamRole::Viewer);

    $this->actingAs($user)
        ->get(route('me', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quietFrom', null)
            ->where('page.quietTo', null));

    NotificationSetting::factory()->for($this->team)->quiet('22:30', '06:45')->create();

    $this->get(route('me', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quietFrom', '22:30')
            ->where('page.quietTo', '06:45'));
});

test('rule errors name the field, not its position in the payload', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Admin));

    $this->put(route('alert-rules.update', ['current_team' => $this->team->slug, 'scope' => 'organization']), ['rules' => [
        ['metric' => 'queue.pending', 'threshold' => 3000, 'severity' => 'warning', 'notifyByEmail' => true, 'enabled' => true],
        ['metric' => 'jobs.failed_per_hour', 'threshold' => 60, 'severity' => 'loud', 'notifyByEmail' => true, 'enabled' => true],
    ]])->assertSessionHasErrors([
        'rules.1.threshold' => 'The threshold field must not be greater than 49.',
        'rules.1.severity' => 'The selected severity is invalid.',
    ]);
});

test('notification setting errors name the field, and an address by what it is', function () {
    $this->actingAs(($this->memberOf)(TeamRole::Admin));

    $this->put(route('alert-settings.update', ['current_team' => $this->team->slug]), [
        'recipients' => ['ops@example.com', 'not-an-address'],
        'webhookUrl' => null,
        'quietFrom' => '23:00',
        'quietTo' => null,
        'timezone' => 'Mars/Olympus',
        'repeatMinutes' => 7,
    ])->assertSessionHasErrors([
        'recipients.1' => 'The address field must be a valid email address.',
        'quietTo' => 'The quiet hours end field is required when quiet hours start is present.',
        'timezone' => 'The selected time zone is invalid.',
        'repeatMinutes' => 'The selected alert repeat is invalid.',
    ]);
});
