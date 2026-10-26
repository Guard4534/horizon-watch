<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * @return Collection<int, RoutingRoute>
 */
function teamRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => array_intersect($route->parameterNames(), ['current_team', 'team']) !== [])
        ->values();
}

function changesState(RoutingRoute $route): bool
{
    return array_diff($route->methods(), ['GET', 'HEAD']) !== [];
}

function hasRoleOrAbilityCheck(RoutingRoute $route): bool
{
    return collect($route->gatherMiddleware())->contains(fn (mixed $middleware) => is_string($middleware) && (
        str_starts_with($middleware, EnsureTeamMembership::class.':') || str_starts_with($middleware, 'can:')
    ));
}

/**
 * @return list<string>
 */
function routesLeftToTheirController(): array
{
    return [
        'applications.test-connection',
        'environments.test-connection',
        'alerts.mute',
        'alerts.unmute',
        'alerts.handle',
        'teams.update',
        'teams.destroy',
        'teams.switch',
        'teams.leave',
    ];
}

test('the organization routes are found', function () {
    expect(teamRoutes()->map(fn (RoutingRoute $route) => $route->getName()))
        ->toContain('wall', 'members.invitations.store', 'teams.update')
        ->and(teamRoutes()->count())->toBeGreaterThan(40);
});

test('every organization route needs a signed-in member of that organization', function () {
    $unguarded = teamRoutes()
        ->reject(function (RoutingRoute $route) {
            $middleware = collect($route->gatherMiddleware())->filter(fn (mixed $entry) => is_string($entry));

            return $middleware->contains(fn (string $entry) => $entry === 'auth' || str_starts_with($entry, 'auth:'))
                && $middleware->contains(fn (string $entry) => $entry === EnsureTeamMembership::class || str_starts_with($entry, EnsureTeamMembership::class.':'));
        })
        ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

test('every organization route that changes something checks a role or an ability', function () {
    $unchecked = teamRoutes()
        ->filter(fn (RoutingRoute $route) => changesState($route))
        ->reject(fn (RoutingRoute $route) => hasRoleOrAbilityCheck($route))
        ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
        ->sort()
        ->values()
        ->all();

    expect($unchecked)->toEqualCanonicalizing(routesLeftToTheirController());
});

test('the routes left to their controller still exist and still have no route check', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and(changesState($route))->toBeTrue()
        ->and(hasRoleOrAbilityCheck($route))->toBeFalse();
})->with(routesLeftToTheirController());

test('every account settings route needs a signed-in user', function () {
    $settings = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => $route->uri() === 'settings' || str_starts_with($route->uri(), 'settings/'));

    $unguarded = $settings
        ->reject(fn (RoutingRoute $route) => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route) => $route->getName() ?? $route->uri())
        ->values()
        ->all();

    expect($settings->count())->toBeGreaterThanOrEqual(13)
        ->and($unguarded)->toBe([]);
});

test('the account settings routes are declared in one signed-in group', function () {
    expect(substr_count((string) file_get_contents(base_path('routes/settings.php')), "Route::middleware(['auth'])->group("))->toBe(1);
});
