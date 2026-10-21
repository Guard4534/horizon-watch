<?php

use App\Enums\EnvironmentType;

test('a name suggests the type its first segment stands for', function (string $name, ?EnvironmentType $type) {
    expect(EnvironmentType::suggestFor($name))->toBe($type);
})->with([
    'production' => ['production', EnvironmentType::Production],
    'prod with a region' => ['prod-eu', EnvironmentType::Production],
    'prd' => ['prd', EnvironmentType::Production],
    'live with a dot' => ['live.west', EnvironmentType::Production],
    'upper case, underscore and spaces around' => ['  PROD_US ', EnvironmentType::Production],
    'a space as separator' => ['production 2', EnvironmentType::Production],
    'preprod' => ['preprod', EnvironmentType::Preprod],
    'pre-production' => ['pre-production', EnvironmentType::Preprod],
    'pre-production with a suffix' => ['pre-production-2', EnvironmentType::Preprod],
    'uat' => ['uat', EnvironmentType::Preprod],
    'staging' => ['staging', EnvironmentType::Staging],
    'stage with a number' => ['stage-1', EnvironmentType::Staging],
    'develop' => ['develop', EnvironmentType::Development],
    'development' => ['development', EnvironmentType::Development],
    'dev' => ['dev', EnvironmentType::Development],
    'test' => ['test', EnvironmentType::Testing],
    'testing' => ['testing', EnvironmentType::Testing],
    'qa with an underscore' => ['qa_2', EnvironmentType::Testing],
    'demo with a suffix' => ['demo-fair', EnvironmentType::Demo],
    'a word starting with prod' => ['products', null],
    'production as a suffix' => ['eu-production', null],
    'the old worker name' => ['worker-batch', null],
    'empty' => ['', null],
]);

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
