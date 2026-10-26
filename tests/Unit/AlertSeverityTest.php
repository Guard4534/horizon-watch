<?php

use App\Enums\AlertSeverity;

test('the worst of several severities is critical as soon as one is critical', function () {
    expect(AlertSeverity::worstOf([]))->toBeNull()
        ->and(AlertSeverity::worstOf([AlertSeverity::Warning, AlertSeverity::Warning]))->toBe(AlertSeverity::Warning)
        ->and(AlertSeverity::worstOf([AlertSeverity::Warning, AlertSeverity::Critical]))->toBe(AlertSeverity::Critical);
});
