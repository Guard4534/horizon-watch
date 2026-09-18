<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentColor;
use App\Enums\Locale;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00:00'));

    $this->team = Team::factory()->create();
    $this->application = Application::factory()->for($this->team)->create(['name' => 'Billing']);
    $this->production = Environment::factory()->for($this->application)->production()->create();
    $this->staging = Environment::factory()->for($this->application)->staging()->create();
    $this->alert = Alert::factory()->for($this->production)->critical()->create([
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 2000,
        'unit' => 'job',
        'value' => 4312,
    ]);

    $this->memberAs = function (TeamRole $role, MemberVisibility $visibility = MemberVisibility::All, ?Locale $locale = null): User {
        $user = User::factory()->create(['locale' => $locale]);
        $this->team->members()->attach($user, ['role' => $role->value, 'visibility' => $visibility->value]);
        $user->switchTeam($this->team);

        return $user;
    };

    $this->preview = fn (?string $id = null, ?Team $team = null) => route('alerts.preview', [
        'current_team' => ($team ?? $this->team)->slug,
        'alert' => $id ?? $this->alert->id,
    ]);
});

test('a manager sees the opening email of the alert as a page of its own', function (TeamRole $role) {
    $response = $this->actingAs(($this->memberAs)($role))->get(($this->preview)());

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:");

    expect($response->getContent())->toContain('4,312 jobs pending')
        ->toContain('Billing · production')
        ->toContain('Open the panel');
})->with([TeamRole::Owner, TeamRole::Admin]);

test('the preview loads nothing from outside the page', function () {
    $html = $this->actingAs(($this->memberAs)(TeamRole::Owner))->get(($this->preview)())->getContent();

    expect($html)->not->toContain('<img')
        ->not->toContain('<link')
        ->not->toContain('<script')
        ->not->toContain('@import')
        ->not->toContain('url(')
        ->not->toMatch('/\ssrc\s*=/i');
});

test('the preview speaks the language of the viewer', function () {
    $this->actingAs(($this->memberAs)(TeamRole::Owner, locale: Locale::It))
        ->get(($this->preview)())
        ->assertOk()
        ->assertSee('Ambiente');
});

test('those who may not manage the alert rules cannot preview', function (TeamRole $role) {
    $this->actingAs(($this->memberAs)($role))->get(($this->preview)())->assertForbidden();
})->with([TeamRole::Member, TeamRole::Viewer]);

test('an alert of an environment the viewer does not watch does not exist for them', function (TeamRole $role) {
    $this->actingAs(($this->memberAs)($role, MemberVisibility::NonProduction))
        ->get(($this->preview)())
        ->assertNotFound();
})->with([TeamRole::Admin, TeamRole::Member]);

test('an alert of another organization does not exist here', function () {
    $user = ($this->memberAs)(TeamRole::Owner);
    $other = Team::factory()->create();
    $other->members()->attach($user, ['role' => TeamRole::Owner->value, 'visibility' => MemberVisibility::All->value]);
    $foreign = Alert::factory()->for(Environment::factory()->for(Application::factory()->for($other)))->create();

    $this->actingAs($user)->get(($this->preview)($foreign->id))->assertNotFound();
});

test('an id that is not an alert answers not found', function (string $id) {
    $this->actingAs(($this->memberAs)(TeamRole::Owner))->get(($this->preview)($id))->assertNotFound();
})->with(['not-a-uuid', 'fd2a4c1e-6d7e-4a44-9c3e-7a1f0b2c9d11']);

test('someone outside the organization cannot preview its alerts', function () {
    $this->actingAs(User::factory()->create())->get(($this->preview)())->assertForbidden();
});

test('the alert of a deleted environment still previews for a manager who sees everything', function () {
    $orphan = Alert::factory()->withoutEnvironment()->resolved()->create([
        'team_id' => $this->team->id,
        'application_name' => 'Billing',
        'environment_name' => 'legacy',
        'environment_color' => EnvironmentColor::Develop,
    ]);

    $this->actingAs(($this->memberAs)(TeamRole::Owner))
        ->get(($this->preview)($orphan->id))
        ->assertOk()
        ->assertSee('Billing · legacy');
});
