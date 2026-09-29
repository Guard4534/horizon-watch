<?php

use App\Models\Application;
use App\Models\Environment;

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
