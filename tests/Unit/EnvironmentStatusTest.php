<?php

use App\Enums\EnvironmentStatus;

test('down environments are the most severe and active ones the least', function () {
    expect(EnvironmentStatus::Inactive->severity())->toBe(0)
        ->and(EnvironmentStatus::Unreachable->severity())->toBe(0)
        ->and(EnvironmentStatus::Paused->severity())->toBe(1)
        ->and(EnvironmentStatus::Degraded->severity())->toBe(2)
        ->and(EnvironmentStatus::Active->severity())->toBe(3);
});

test('only inactive and unreachable count as down', function () {
    expect(EnvironmentStatus::Inactive->isDown())->toBeTrue()
        ->and(EnvironmentStatus::Unreachable->isDown())->toBeTrue()
        ->and(EnvironmentStatus::Paused->isDown())->toBeFalse()
        ->and(EnvironmentStatus::Active->isHealthy())->toBeTrue()
        ->and(EnvironmentStatus::Degraded->isHealthy())->toBeFalse();
});
