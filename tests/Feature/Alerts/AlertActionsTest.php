<?php

use App\Actions\Alerts\HandleAlert;
use App\Actions\Alerts\MuteAlert;
use App\Actions\Alerts\UnmuteAlert;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\EnvironmentColor;
use App\Enums\MemberVisibility;
use App\Enums\MuteDuration;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\Grants;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00'));

    $this->team = Team::factory()->create();
    $this->application = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $this->production = Environment::factory()->for($this->application)->production()->create();
    $this->staging = Environment::factory()->for($this->application)->staging()->create();
    $this->alert = Alert::factory()->for($this->production)->critical()->create(['metric' => AlertRuleMetric::EndpointUnreachable]);

    $this->memberAs = function (TeamRole $role, MemberVisibility $visibility = MemberVisibility::All): User {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->url = fn (string $action, ?string $id = null, ?Team $team = null) => route($action, [
        'current_team' => ($team ?? $this->team)->slug,
        'alert' => $id ?? $this->alert->id,
    ]);
});

test('a role that may mute silences an alert for a while and is named on it', function (TeamRole $role) {
    $user = ($this->memberAs)($role);

    $this->actingAs($user)
        ->from(route('alerts.index', ['current_team' => $this->team->slug]))
        ->post(($this->url)('alerts.mute'), ['duration' => '4h'])
        ->assertRedirect(route('alerts.index', ['current_team' => $this->team->slug]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alert muted.']);

    $alert = $this->alert->fresh();

    expect($alert->muted_until->toDateTimeString())->toBe('2026-09-18 14:00:00')
        ->and($alert->muted_indefinitely)->toBeFalse()
        ->and($alert->muted_by)->toBe($user->id)
        ->and($alert->state(CarbonImmutable::now()))->toBe(AlertState::Muted);
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member]);

test('each duration sets its own end', function (string $duration, ?string $until, bool $indefinitely) {
    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->post(($this->url)('alerts.mute'), ['duration' => $duration])
        ->assertRedirect();

    $alert = $this->alert->fresh();

    expect($alert->muted_until?->toDateTimeString())->toBe($until)
        ->and($alert->muted_indefinitely)->toBe($indefinitely);
})->with([
    'one hour' => ['1h', '2026-09-18 11:00:00', false],
    'four hours' => ['4h', '2026-09-18 14:00:00', false],
    'a day' => ['24h', '2026-09-19 10:00:00', false],
    'until resolved' => ['resolved', null, true],
]);

test('muting again replaces the previous mute', function () {
    $this->alert->update(['muted_indefinitely' => true]);
    $user = ($this->memberAs)(TeamRole::Admin);

    $this->actingAs($user)->post(($this->url)('alerts.mute'), ['duration' => '1h'])->assertRedirect();

    expect($this->alert->fresh()->muted_indefinitely)->toBeFalse()
        ->and($this->alert->fresh()->muted_until->toDateTimeString())->toBe('2026-09-18 11:00:00');

    $this->actingAs($user)->post(($this->url)('alerts.mute'), ['duration' => 'resolved'])->assertRedirect();

    expect($this->alert->fresh()->muted_indefinitely)->toBeTrue()
        ->and($this->alert->fresh()->muted_until)->toBeNull();
});

test('a mute that runs out puts the alert back among the open ones', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->post(($this->url)('alerts.mute'), ['duration' => '1h'])
        ->assertRedirect();

    app()->forgetScopedInstances();
    expect(app(MonitoringRepository::class)->openAlerts($this->team))->toBe([]);

    $this->travel(61)->minutes();
    app()->forgetScopedInstances();

    expect(array_column(app(MonitoringRepository::class)->openAlerts($this->team), 'id'))->toBe([$this->alert->id])
        ->and($this->alert->fresh()->state(CarbonImmutable::now()))->toBe(AlertState::Open);
});

test('an unknown duration is refused', function (mixed $duration) {
    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->postJson(($this->url)('alerts.mute'), ['duration' => $duration])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration');

    expect($this->alert->fresh()->muted_until)->toBeNull()
        ->and($this->alert->fresh()->muted_indefinitely)->toBeFalse();
})->with(['2h', '', null, 60]);

test('a role that may mute lifts the mute', function (TeamRole $role) {
    $this->alert->update(['muted_until' => now()->addHour(), 'muted_by' => User::factory()->create()->id]);

    $this->actingAs(($this->memberAs)($role))
        ->delete(($this->url)('alerts.unmute'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alert unmuted.']);

    $alert = $this->alert->fresh();

    expect($alert->muted_until)->toBeNull()
        ->and($alert->muted_indefinitely)->toBeFalse()
        ->and($alert->muted_by)->toBeNull();
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member]);

test('unmuting clears a mute until resolution too', function () {
    $this->alert->update(['muted_indefinitely' => true]);

    $this->actingAs(($this->memberAs)(TeamRole::Member))->delete(($this->url)('alerts.unmute'))->assertRedirect();

    expect($this->alert->fresh()->muted_indefinitely)->toBeFalse();
});

test('a role that may handle takes the alert in charge, which stays open', function (TeamRole $role) {
    $user = ($this->memberAs)($role);

    $this->actingAs($user)
        ->post(($this->url)('alerts.handle'))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alert marked as handled.']);

    $alert = $this->alert->fresh();

    expect($alert->handled_at->toDateTimeString())->toBe('2026-09-18 10:00:00')
        ->and($alert->handled_by)->toBe($user->id)
        ->and($alert->resolved_at)->toBeNull()
        ->and($alert->state(CarbonImmutable::now()))->toBe(AlertState::Open);
})->with([TeamRole::Owner, TeamRole::Admin, TeamRole::Member]);

test('taking an alert in charge twice keeps the first one who did', function () {
    $first = ($this->memberAs)(TeamRole::Member);
    $second = ($this->memberAs)(TeamRole::Admin);

    $this->actingAs($first)->post(($this->url)('alerts.handle'))->assertRedirect();
    $this->travel(5)->minutes();
    $this->actingAs($second)->post(($this->url)('alerts.handle'))->assertRedirect();

    $alert = $this->alert->fresh();

    expect($alert->handled_by)->toBe($first->id)
        ->and($alert->handled_at->toDateTimeString())->toBe('2026-09-18 10:00:00');
});

test('a viewer may do none of the three', function (string $method, string $action) {
    $this->actingAs(($this->memberAs)(TeamRole::Viewer))
        ->{$method}(($this->url)($action), ['duration' => '1h'])
        ->assertForbidden();

    $alert = $this->alert->fresh();

    expect($alert->muted_until)->toBeNull()
        ->and($alert->handled_at)->toBeNull();
})->with([
    ['post', 'alerts.mute'],
    ['delete', 'alerts.unmute'],
    ['post', 'alerts.handle'],
]);

test('a viewer is refused before the duration is checked', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Viewer))
        ->postJson(($this->url)('alerts.mute'), ['duration' => 'forever'])
        ->assertForbidden();
});

test('an alert of an environment the member does not watch does not exist for them', function (string $method, string $action, TeamRole $role) {
    $this->actingAs(($this->memberAs)($role, MemberVisibility::NonProduction))
        ->{$method}(($this->url)($action), ['duration' => 'forever'])
        ->assertNotFound();

    expect($this->alert->fresh()->handled_at)->toBeNull();
})->with([
    ['post', 'alerts.mute'],
    ['delete', 'alerts.unmute'],
    ['post', 'alerts.handle'],
])->with([TeamRole::Member, TeamRole::Viewer]);

test('a manual member acts only on the environments granted to them', function () {
    $user = ($this->memberAs)(TeamRole::Member, MemberVisibility::Manual);
    $staging = Alert::factory()->for($this->staging)->create();
    Grants::give($user->id, $this->staging->id);

    $this->actingAs($user)->post(($this->url)('alerts.handle'))->assertNotFound();
    $this->actingAs($user)->post(($this->url)('alerts.handle', $staging->id))->assertRedirect();

    expect($staging->fresh()->handled_by)->toBe($user->id);
});

test('an alert of another organization does not exist here', function (string $method, string $action) {
    $user = ($this->memberAs)(TeamRole::Owner);
    $other = Team::factory()->create();
    $other->members()->attach($user, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $foreign = Alert::factory()->for(Environment::factory()->for(Application::factory()->for($other)))->create();

    $this->actingAs($user)->{$method}(($this->url)($action, $foreign->id), ['duration' => '1h'])->assertNotFound();

    expect($foreign->fresh()->muted_until)->toBeNull()
        ->and($foreign->fresh()->handled_at)->toBeNull();
})->with([
    ['post', 'alerts.mute'],
    ['delete', 'alerts.unmute'],
    ['post', 'alerts.handle'],
]);

test('an alert of a deleted environment of another organization does not exist here', function () {
    $foreign = Alert::factory()->withoutEnvironment()->resolved()->create([
        'team_id' => Team::factory()->create()->id,
        'application_name' => 'Billing',
        'environment_name' => 'legacy',
        'environment_color' => EnvironmentColor::Develop,
    ]);

    $this->actingAs(($this->memberAs)(TeamRole::Owner))
        ->postJson(($this->url)('alerts.handle', $foreign->id))
        ->assertNotFound();
});

test('an id that is not an alert answers not found', function (string $id) {
    $this->actingAs(($this->memberAs)(TeamRole::Owner))
        ->post(($this->url)('alerts.handle', $id))
        ->assertNotFound();
})->with(['not-a-uuid', 'fd2a4c1e-6d7e-4a44-9c3e-7a1f0b2c9d11', '1']);

test('someone outside the organization cannot reach its alerts', function () {
    $this->actingAs(User::factory()->create())
        ->post(($this->url)('alerts.handle'))
        ->assertForbidden();
});

test('a resolved alert refuses every action with a reason', function (string $method, string $action) {
    $this->alert->update(['resolved_at' => now()->subMinute()]);

    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->{$method.'Json'}(($this->url)($action), ['duration' => '1h'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alert' => 'This alert is already resolved.']);

    $alert = $this->alert->fresh();

    expect($alert->muted_until)->toBeNull()
        ->and($alert->handled_at)->toBeNull();
})->with([
    ['post', 'alerts.mute'],
    ['delete', 'alerts.unmute'],
    ['post', 'alerts.handle'],
]);

test('a resolved alert sends the page back with the reason', function () {
    $this->alert->update(['resolved_at' => now()->subMinute()]);
    $from = route('alerts.index', ['current_team' => $this->team->slug]);

    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->from($from)
        ->post(($this->url)('alerts.handle'))
        ->assertRedirect($from)
        ->assertSessionHasErrors(['alert' => 'This alert is already resolved.']);
});

test('a viewer is refused before a resolved alert is explained', function () {
    $this->alert->update(['resolved_at' => now()->subMinute()]);

    $this->actingAs(($this->memberAs)(TeamRole::Viewer))
        ->postJson(($this->url)('alerts.handle'))
        ->assertForbidden();
});

test('a resolved alert of a deleted environment exists only for those who see every environment', function () {
    $orphan = Alert::factory()->withoutEnvironment()->resolved()->create([
        'team_id' => $this->team->id,
        'application_name' => 'Billing',
        'environment_name' => 'legacy',
        'environment_color' => EnvironmentColor::Develop,
    ]);

    $this->actingAs(($this->memberAs)(TeamRole::Member, MemberVisibility::NonProduction))
        ->postJson(($this->url)('alerts.handle', $orphan->id))
        ->assertNotFound();

    $this->actingAs(($this->memberAs)(TeamRole::Member))
        ->postJson(($this->url)('alerts.handle', $orphan->id))
        ->assertUnprocessable();
});

test('the routes take the alert by its uuid', function () {
    expect(($this->url)('alerts.mute'))->toEndWith("/alerts/{$this->alert->id}/mute")
        ->and(($this->url)('alerts.handle'))->toEndWith("/alerts/{$this->alert->id}/handle")
        ->and(Str::isUuid($this->alert->id))->toBeTrue();
});

test('an alert resolved between the read and the write is left alone by all three actions', function (string $action) {
    $user = ($this->memberAs)(TeamRole::Owner);
    $stale = $this->alert->fresh();
    Alert::query()->whereKey($this->alert->id)->update(['resolved_at' => CarbonImmutable::now()]);

    match ($action) {
        'mute' => app(MuteAlert::class)->handle($stale, $user, MuteDuration::FourHours),
        'unmute' => app(UnmuteAlert::class)->handle($stale),
        'handle' => app(HandleAlert::class)->handle($stale, $user),
    };

    $alert = $this->alert->fresh();

    expect($alert->muted_until)->toBeNull()
        ->and($alert->muted_indefinitely)->toBeFalse()
        ->and($alert->muted_by)->toBeNull()
        ->and($alert->handled_at)->toBeNull()
        ->and($alert->handled_by)->toBeNull();
})->with(['mute', 'unmute', 'handle']);
