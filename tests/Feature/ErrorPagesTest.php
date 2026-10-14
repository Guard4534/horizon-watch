<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;

    $this->poll = fn (string $component) => [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'page,openAlertCount,panelHealth',
    ];
});

test('a page that does not exist renders the error page in the language of the viewer', function () {
    $this->user->update(['locale' => 'it']);

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'nowhere']))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('locale', 'it')
            ->missing('auth')
            ->missing('openAlertCount'));
});

test('an unknown address renders the error page in the language of the browser', function () {
    $this->withHeader('Accept-Language', 'it-IT,it;q=0.9,en;q=0.8')
        ->get('/nothing/lives/here')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('locale', 'it'));
});

test('an organization address that no longer exists renders the error page in the language of the viewer', function () {
    $this->user->update(['locale' => 'it']);

    $this->actingAs($this->user)
        ->get(route('wall', ['current_team' => 'renamed-organization']))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('locale', 'it'));
});

test('a live poll of an environment that is gone swaps the page for the error page', function () {
    $this->actingAs($this->user)
        ->withHeaders(($this->poll)('monitoring/environments/Show'))
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'nowhere']))
        ->assertNotFound()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 404);
});

test('a live poll of an organization the viewer left renders the forbidden error page', function () {
    $other = Team::factory()->create();

    $this->actingAs($this->user)
        ->withHeaders(($this->poll)('monitoring/Wall'))
        ->get(route('wall', ['current_team' => $other->slug]))
        ->assertForbidden()
        ->assertJsonPath('component', 'Error')
        ->assertJsonPath('props.status', 403);
});

test('an action that fails on an Inertia visit goes back with a toast instead of the error page', function () {
    $this->user->update(['locale' => 'it']);

    $this->actingAs($this->user)
        ->from(route('wall', ['current_team' => $this->slug]))
        ->withHeader('X-Inertia', 'true')
        ->post(route('alerts.mute', ['current_team' => $this->slug, 'alert' => (string) Str::uuid()]), ['duration' => '1h'])
        ->assertRedirect(route('wall', ['current_team' => $this->slug]))
        ->assertStatus(303)
        ->assertInertiaFlash('toast.message', 'Non è più possibile: la pagina non era aggiornata.');

    Route::middleware('web')->delete('/__broken', fn () => throw new RuntimeException('The disk is on fire'));

    config(['app.debug' => false]);

    $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->delete('/__broken')
        ->assertRedirect('/settings/profile')
        ->assertInertiaFlash('toast.message', 'Qualcosa è andato storto. Riprova tra un momento.');
});

test('maintenance renders the error page', function () {
    Route::middleware('web')->get('/__down', fn () => abort(503));

    $this->get('/__down')
        ->assertServiceUnavailable()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503));
});

test('a server error renders the error page, unless the application is being debugged', function () {
    Route::middleware('web')->get('/__broken', fn () => throw new RuntimeException('The disk is on fire'));

    config(['app.debug' => false]);

    $this->get('/__broken')
        ->assertInternalServerError()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 500))
        ->assertDontSee('The disk is on fire');

    config(['app.debug' => true]);

    $this->get('/__broken')
        ->assertInternalServerError()
        ->assertSee('The disk is on fire');
});

test('an expired page goes back with a toast on an Inertia visit and renders the error page otherwise', function () {
    Route::middleware('web')->post('/__expired', fn () => throw new TokenMismatchException);

    $this->post('/__expired')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 419));

    $this->from('/settings/profile')
        ->withHeader('X-Inertia', 'true')
        ->post('/__expired')
        ->assertRedirect('/settings/profile')
        ->assertInertiaFlash('toast.message', 'The page had expired. Try again.');
});

test('json requests keep their json errors', function () {
    $this->getJson('/nothing/lives/here')
        ->assertNotFound()
        ->assertJsonMissingPath('component')
        ->assertJsonStructure(['message']);
});

test('the statuses the client handles itself are left alone', function () {
    Route::middleware('web')->get('/__gone', fn () => abort(410));

    config(['app.debug' => false]);

    $this->get('/__gone')
        ->assertStatus(410)
        ->assertDontSee('data-page', false);
});
