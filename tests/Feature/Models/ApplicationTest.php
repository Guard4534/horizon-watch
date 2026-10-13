<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;

test('renaming an application keeps its own slug and its environments slugs', function () {
    $application = Application::factory()->create(['name' => 'Invoice Desk']);
    $environment = Environment::factory()->production()->create(['application_id' => $application->id]);

    expect($application->slug)->toBe('invoice-desk')
        ->and($environment->slug)->toBe('invoice-desk-production');

    $application->update(['name' => 'Invoice Desk Renamed']);
    $environment->refresh();

    expect($application->slug)->toBe('invoice-desk')
        ->and($environment->slug)->toBe('invoice-desk-production');
});

test('a name without letters or digits falls back to a slug that can be routed', function () {
    $team = Team::factory()->create();

    $first = Application::factory()->create(['team_id' => $team->id, 'name' => '!!!']);
    $second = Application::factory()->create(['team_id' => $team->id, 'name' => '★★★']);
    $environment = Environment::factory()->production()->create(['application_id' => $first->id]);

    expect($first->slug)->toBe('application')
        ->and($second->slug)->toBe('application-1')
        ->and($environment->slug)->toBe('application-production');
});
