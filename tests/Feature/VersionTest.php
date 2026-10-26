<?php

use App\Models\User;
use App\Support\Version;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

test('the version comes from the image and falls back to dev', function () {
    expect(config('horizon-watch.version'))->toBe('dev')
        ->and(Version::current())->toBe('dev')
        ->and(Version::userAgent())->toBe('HorizonWatch/dev');

    config(['horizon-watch.version' => '1.0.0']);

    expect(Version::current())->toBe('1.0.0')
        ->and(Version::userAgent())->toBe('HorizonWatch/1.0.0');

    config(['horizon-watch.version' => ' ']);

    expect(Version::current())->toBe('dev');
});

test('a version built from a git tag is shown without the v of the tag', function (string $built, string $shown) {
    config(['horizon-watch.version' => $built]);

    expect(Version::current())->toBe($shown)
        ->and(Version::userAgent())->toBe("HorizonWatch/{$shown}");
})->with([
    'stable tag' => ['v1.0.0', '1.0.0'],
    'pre-release tag' => ['v1.1.0-rc.1', '1.1.0-rc.1'],
    'already bare' => ['1.0.0', '1.0.0'],
    'a word that starts with v' => ['vnext', 'vnext'],
    'development build' => ['dev', 'dev'],
]);

test('artisan about shows the running version', function () {
    config(['horizon-watch.version' => '1.0.0']);

    Artisan::call('about', ['--only' => 'horizon_watch', '--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe(['horizon_watch' => ['version' => '1.0.0']]);
});

test('signed-in pages share the running version, guest pages do not', function () {
    config(['horizon-watch.version' => '1.0.0']);
    $user = User::factory()->create();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('version', null));

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('version', '1.0.0'));
});
