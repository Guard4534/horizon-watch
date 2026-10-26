<?php

use App\Enums\EnvironmentType;

test('only the production type is production', function () {
    expect(array_values(array_filter(
        EnvironmentType::cases(),
        fn (EnvironmentType $type) => $type->isProduction(),
    )))->toBe([EnvironmentType::Production]);
});

test('the options list every type in enum order with a translated label', function () {
    expect(EnvironmentType::options())->toBe([
        ['value' => 'production', 'label' => 'Production'],
        ['value' => 'preprod', 'label' => 'Pre-production'],
        ['value' => 'staging', 'label' => 'Staging'],
        ['value' => 'development', 'label' => 'Development'],
        ['value' => 'testing', 'label' => 'Testing'],
        ['value' => 'demo', 'label' => 'Demo'],
    ]);

    app()->setLocale('it');

    expect(array_column(EnvironmentType::options(), 'label'))
        ->toBe(['Produzione', 'Pre-produzione', 'Staging', 'Sviluppo', 'Test', 'Demo']);
});
