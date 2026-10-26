<?php

use App\Actions\Teams\DeleteTeamApplications;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Jobs\PurgeDeletedTeam;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Grants;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $this->application = Application::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->application)->production()->create(['polling_enabled' => true]);
    EnvironmentSnapshot::factory()->for($this->environment)->count(5)->create();
    Grants::give($this->member->id, $this->environment->id);
});

function snapshotDeletes(Closure $during): int
{
    $count = 0;

    DB::listen(function (QueryExecuted $query) use (&$count) {
        if (str_starts_with($query->sql, 'delete from "environment_snapshots"')) {
            $count++;
        }
    });

    $during();

    return $count;
}

test('deleting an organization hides it at once and leaves its readings to a queued purge', function () {
    Queue::fake();

    $this->actingAs($this->owner)
        ->delete(route('teams.destroy', $this->team), ['name' => $this->team->name])
        ->assertRedirect();

    $this->assertSoftDeleted('teams', ['id' => $this->team->id]);
    $this->assertDatabaseHas('environments', ['id' => $this->environment->id, 'polling_enabled' => false]);
    $this->assertDatabaseMissing('environment_user', ['environment_id' => $this->environment->id]);
    expect(EnvironmentSnapshot::query()->count())->toBe(5);

    Queue::assertPushed(PurgeDeletedTeam::class, fn (PurgeDeletedTeam $job) => $job->teamId === $this->team->id);
});

test('the purge deletes the readings in chunks, then the applications', function () {
    config(['horizon-watch.readings.prune_chunk' => 2]);
    $this->team->delete();

    $deletes = snapshotDeletes(fn () => PurgeDeletedTeam::dispatchSync($this->team->id));

    expect($deletes)->toBe(4)
        ->and(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(Application::query()->whereKey($this->application->id)->exists())->toBeFalse()
        ->and(Environment::query()->whereKey($this->environment->id)->exists())->toBeFalse();
});

test('a purge that runs out of time hands the rest to a new job', function () {
    config([
        'horizon-watch.readings.prune_chunk' => 2,
        'horizon-watch.readings.purge_budget_seconds' => 0.0,
    ]);
    $this->team->delete();
    Queue::fake();

    (new PurgeDeletedTeam($this->team->id))->handle(app(DeleteTeamApplications::class));

    expect(EnvironmentSnapshot::query()->count())->toBe(3)
        ->and(Application::query()->whereKey($this->application->id)->exists())->toBeTrue();

    Queue::assertPushed(PurgeDeletedTeam::class, fn (PurgeDeletedTeam $job) => $job->teamId === $this->team->id);
});

test('the purge leaves a live organization alone', function () {
    PurgeDeletedTeam::dispatchSync($this->team->id);

    expect(EnvironmentSnapshot::query()->count())->toBe(5)
        ->and(Application::query()->whereKey($this->application->id)->exists())->toBeTrue();
});

test('the daily prune finishes what a failed purge left behind', function () {
    $this->team->delete();
    $live = Environment::factory()->create();
    EnvironmentSnapshot::factory()->for($live)->create();

    $this->artisan('monitoring:prune')
        ->expectsOutput('Purged 1 deleted organization.')
        ->assertSuccessful();

    expect(Application::query()->whereKey($this->application->id)->exists())->toBeFalse()
        ->and(EnvironmentSnapshot::query()->where('environment_id', $live->id)->count())->toBe(1);
});

test('deleting an application removes its readings in chunks before the application', function () {
    config(['horizon-watch.readings.prune_chunk' => 2]);
    $this->team->members()->updateExistingPivot($this->owner->id, ['visibility' => MemberVisibility::All->value]);
    $this->owner->switchTeam($this->team);

    $deletes = snapshotDeletes(fn () => $this->actingAs($this->owner)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertRedirect());

    expect($deletes)->toBe(4)
        ->and(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(Application::query()->whereKey($this->application->id)->exists())->toBeFalse();
});

test('deleting an environment removes its readings in chunks before the environment', function () {
    config(['horizon-watch.readings.prune_chunk' => 2]);
    $this->owner->switchTeam($this->team);

    $deletes = snapshotDeletes(fn () => $this->actingAs($this->owner)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $this->environment->slug]), [
            'name' => $this->environment->name,
        ])
        ->assertRedirect());

    expect($deletes)->toBe(4)
        ->and(Environment::query()->whereKey($this->environment->id)->exists())->toBeFalse();
});

test('a delete that dies while removing the readings leaves the environment paused', function (Closure $delete) {
    config(['horizon-watch.readings.prune_chunk' => 2]);
    $this->team->members()->updateExistingPivot($this->owner->id, ['visibility' => MemberVisibility::All->value]);
    $this->owner->switchTeam($this->team);

    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'delete from "environment_snapshots"')) {
            throw new RuntimeException('The request died.');
        }
    });

    $delete($this)->assertServerError();

    expect(Environment::query()->find($this->environment->id)->polling_enabled)->toBeFalse()
        ->and(EnvironmentSnapshot::query()->count())->toBe(3);
})->with([
    'application' => [fn ($test) => $test->actingAs($test->owner)->delete(route('applications.destroy', ['current_team' => $test->team->slug, 'application' => $test->application->slug]), ['name' => $test->application->name])],
    'environment' => [fn ($test) => $test->actingAs($test->owner)->delete(route('environments.destroy', ['current_team' => $test->team->slug, 'environment' => $test->environment->slug]), ['name' => $test->environment->name])],
]);
