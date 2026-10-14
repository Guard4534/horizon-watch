<?php

use App\Models\User;
use App\Support\Version;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

test('the version comes from the image and falls back to dev', function () {
    expect(config('horizon-watch.version'))->toBe('dev')
        ->and(Version::current())->toBe('dev')
        ->and(Version::userAgent())->toBe('HorizonWatch/dev');

    config(['horizon-watch.version' => 'v1.0.0']);

    expect(Version::current())->toBe('v1.0.0')
        ->and(Version::userAgent())->toBe('HorizonWatch/v1.0.0');

    config(['horizon-watch.version' => ' ']);

    expect(Version::current())->toBe('dev');
});

test('artisan about shows the running version', function () {
    config(['horizon-watch.version' => 'v1.0.0']);

    Artisan::call('about', ['--only' => 'horizon_watch', '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe(['horizon_watch' => ['version' => 'v1.0.0']]);
});

test('signed-in pages share the running version, guest pages do not', function () {
    config(['horizon-watch.version' => 'v1.0.0']);
    $user = User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('version', null));

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('version', 'v1.0.0'));
});
