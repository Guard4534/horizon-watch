<?php

use App\Enums\TeamRole;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every role opens the profile page, with the organization member count', function (TeamRole $role) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $team->members()->attach(User::factory()->count(2)->create(), ['role' => TeamRole::Viewer->value]);
    $user->switchTeam($team);

    $expected = $team->members()->count();

    $this->actingAs($user)
        ->get(route('me', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Me')
            ->where('page', ['memberCount' => $expected, 'alertEmails' => false, 'quietFrom' => null, 'quietTo' => null, 'timezone' => NotificationSetting::defaultTimezone()]));
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'member' => TeamRole::Member,
    'viewer' => TeamRole::Viewer,
]);

test('the profile page shows the quiet hours of the organization in its own time zone', function () {
    config(['horizon-watch.notifications.default_timezone' => 'Europe/Lisbon']);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Viewer->value]);
    $user->switchTeam($team);

    $page = fn () => $this->actingAs($user)->get(route('me', ['current_team' => $team->slug]));

    $page()->assertInertia(fn (Assert $page) => $page->where('page.timezone', 'Europe/Lisbon'));

    NotificationSetting::factory()->for($team)->create(['quiet_from' => '22:00', 'quiet_to' => '06:30', 'timezone' => 'America/New_York']);

    $page()->assertInertia(fn (Assert $page) => $page
        ->where('page.quietFrom', '22:00')
        ->where('page.quietTo', '06:30')
        ->where('page.timezone', 'America/New_York'));
});

test('another organization shows up only as its name and the viewer\'s role in it', function () {
    $user = User::factory()->create(['name' => 'Ada Viewer']);
    $current = $user->currentTeam;

    $other = Team::factory()->create(['name' => 'Northwind Ops']);
    $other->members()->attach($user, ['role' => TeamRole::Viewer->value]);
    $stranger = User::factory()->create(['name' => 'Grace Stranger', 'email' => 'grace@example.net']);
    $other->members()->attach($stranger, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($user)
        ->get(route('me', ['current_team' => $current->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.memberCount', 1)
            ->where('teams', fn ($teams) => collect($teams)->contains(
                fn ($team) => $team['name'] === 'Northwind Ops' && $team['role'] === 'viewer',
            )));

    expect($response->getContent())
        ->not->toContain('grace@example.net')
        ->not->toContain('Grace Stranger');
});

test('someone outside the organization cannot open its profile page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('me', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('a guest is sent to the login page', function () {
    $team = Team::factory()->create();
    User::factory()->create();

    $this->get(route('me', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});

test('the profile page is for phones only: a wider viewport is replaced with the settings profile', function () {
    $page = file_get_contents(resource_path('js/pages/monitoring/Me.vue'));
    $guard = file_get_contents(resource_path('js/composables/useMobileOnlyPage.ts'));

    expect($page)->toContain('useMobileOnlyPage(profileEdit())');
    expect($guard)->toContain('router.visit(fallback, { replace: true })');
});

test('the settings profile a wider viewport lands on offers the same alert-email switch', function () {
    $user = User::factory()->create(['alert_emails' => true]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('alertEmails', true));
});
