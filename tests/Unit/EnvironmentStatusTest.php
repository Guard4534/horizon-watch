<?php

use App\Enums\EnvironmentStatus;

test('down environments are the most severe and active ones the least', function () {
    expect(EnvironmentStatus::Inactive->severity())->toBe(0)
        ->and(EnvironmentStatus::Unreachable->severity())->toBe(0)
        ->and(EnvironmentStatus::Paused->severity())->toBe(1)
        ->and(EnvironmentStatus::Stale->severity())->toBe(1)
        ->and(EnvironmentStatus::Degraded->severity())->toBe(2)
        ->and(EnvironmentStatus::Active->severity())->toBe(3);
});

test('only inactive and unreachable count as down', function () {
    expect(EnvironmentStatus::Inactive->isDown())->toBeTrue()
        ->and(EnvironmentStatus::Unreachable->isDown())->toBeTrue()
        ->and(EnvironmentStatus::Paused->isDown())->toBeFalse()
        ->and(EnvironmentStatus::Stale->isDown())->toBeFalse()
        ->and(EnvironmentStatus::Stale->isHealthy())->toBeFalse()
        ->and(EnvironmentStatus::Active->isHealthy())->toBeTrue()
        ->and(EnvironmentStatus::Degraded->isHealthy())->toBeFalse();
});

test('the worst of several statuses is the most severe, and there is none without a status', function () {
    expect(EnvironmentStatus::worstOf([EnvironmentStatus::Active, EnvironmentStatus::Stale, null, EnvironmentStatus::Degraded]))->toBe(EnvironmentStatus::Stale)
        ->and(EnvironmentStatus::worstOf([EnvironmentStatus::Degraded, EnvironmentStatus::Unreachable]))->toBe(EnvironmentStatus::Unreachable)
        ->and(EnvironmentStatus::worstOf([EnvironmentStatus::Active]))->toBe(EnvironmentStatus::Active)
        ->and(EnvironmentStatus::worstOf([null]))->toBeNull()
        ->and(EnvironmentStatus::worstOf([]))->toBeNull();
});
