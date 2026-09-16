<?php

use App\Models\Application;
use App\Models\Environment;

test('renaming an application keeps its own slug and its environments slugs', function () {
    $application = Application::factory()->create(['name' => 'Fatturaomatic']);
    $environment = Environment::factory()->production()->create(['application_id' => $application->id]);

    expect($application->slug)->toBe('fatturaomatic')
        ->and($environment->slug)->toBe('fatturaomatic-production');

    $application->update(['name' => 'Fatturaomatic Renamed']);
    $environment->refresh();

    expect($application->slug)->toBe('fatturaomatic')
        ->and($environment->slug)->toBe('fatturaomatic-production');
});
