<?php

use App\Actions\Teams\ChangeMemberRole;
use App\Actions\Teams\RemoveMember;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->others = Team::factory()->count(3)->create();
    $this->others->each(fn (Team $team) => $team->members()->attach($this->user, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::All->value,
    ]));
});

function membershipQueries(Closure $during): int
{
    $count = 0;

    DB::listen(function (QueryExecuted $query) use (&$count) {
        if (str_contains($query->sql, 'from "team_members"')) {
            $count++;
        }
    });

    $during();

    return $count;
}

test('a page reads the viewer\'s memberships once, however many organizations and checks ask', function () {
    $queries = membershipQueries(fn () => $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => $this->user->currentTeam->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('teams', 4)
            ->where('teams', fn ($teams) => collect($teams)->where('role', TeamRole::Member->value)->count() === 3)
            ->where('currentTeam.role', TeamRole::Owner->value)));

    expect($queries)->toBe(1);
});

test('a membership changed during the request is read again', function () {
    $team = $this->others->first();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect($this->user->teamRole($team))->toBe(TeamRole::Member);

    app(ChangeMemberRole::class)->handle($team, $owner, $this->user, TeamRole::Admin);

    expect($this->user->teamRole($team))->toBe(TeamRole::Admin)
        ->and($this->user->belongsToTeam($team))->toBeTrue();

    app(RemoveMember::class)->handle($team, $this->user);

    expect($this->user->belongsToTeam($team))->toBeFalse()
        ->and($this->user->teamRole($team))->toBeNull();
});

test('a deleted organization no longer counts as one the user belongs to', function () {
    $team = $this->others->first();

    expect($this->user->belongsToTeam($team))->toBeTrue();

    $team->delete();

    expect($this->user->belongsToTeam($team))->toBeFalse();
});

test('a poll from a tab on another organization serves that organization without switching the saved one', function () {
    $home = $this->user->currentTeam;
    $other = $this->others->first();
    $application = Application::factory()->for($other)->create();
    Environment::factory()->for($application)->production()->create();

    $version = app(HandleInertiaRequests::class)->version(request());

    $this->actingAs($this->user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'monitoring/Wall',
            'X-Inertia-Partial-Data' => 'page,openAlertCount',
        ])
        ->get(route('wall', ['current_team' => $other->slug]))
        ->assertOk()
        ->assertJsonCount(1, 'props.page.environments');

    expect($this->user->fresh()->current_team_id)->toBe($home->id);
});

test('a full visit to another organization still switches the saved one', function () {
    $other = $this->others->first();

    $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => $other->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currentTeam.slug', $other->slug));

    expect($this->user->fresh()->current_team_id)->toBe($other->id);
});
