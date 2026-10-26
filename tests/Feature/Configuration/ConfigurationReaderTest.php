<?php

use App\Configuration\ConfigurationDocument;
use App\Configuration\ConfigurationFormat;
use App\Configuration\ConfigurationReader;
use App\Configuration\FormatUpgrade;
use App\Configuration\InvalidConfigurationFile;
use Tests\Support\ConfigurationFiles;

test('a file of the current version is read as it is', function () {
    $document = app(ConfigurationReader::class)->read(json_encode(
        ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => []]]),
        JSON_THROW_ON_ERROR,
    ));

    expect($document->sourceVersion)->toBe(1)
        ->and($document->organization())->toBe('Example Organization')
        ->and($document->exportedAt())->toBe('2026-10-16T09:00:00Z')
        ->and($document->applications())->toBe([['name' => 'Acme Shop', 'environments' => []]])
        ->and($document->alertRules())->toBe([]);
});

test('a byte order mark in front of the file is ignored', function () {
    $document = app(ConfigurationReader::class)->read("\u{FEFF}".json_encode(ConfigurationFiles::document(), JSON_THROW_ON_ERROR));

    expect($document->sourceVersion)->toBe(1);
});

test('a file without alert rules reads as a file with no rules', function () {
    $data = ConfigurationFiles::document();
    unset($data['alert_rules']);

    expect(app(ConfigurationReader::class)->read(json_encode($data, JSON_THROW_ON_ERROR))->alertRules())->toBe([]);
});

test('a file the panel cannot use is refused with the reason', function (string $contents, string $message) {
    expect(fn () => app(ConfigurationReader::class)->read($contents))
        ->toThrow(InvalidConfigurationFile::class, $message);
})->with([
    'malformed json' => ['{"format": "horizon-watch.config",', 'This file is not valid JSON.'],
    'another format' => ['{"format": "something-else", "version": 1, "applications": []}', 'This is not a Horizon Watch configuration file.'],
    'a json list' => ['[]', 'This is not a Horizon Watch configuration file.'],
    'no version' => ['{"format": "horizon-watch.config", "applications": []}', 'This file does not say which format version it uses.'],
    'a version in quotes' => ['{"format": "horizon-watch.config", "version": "1", "applications": []}', 'This file does not say which format version it uses.'],
    'version zero' => ['{"format": "horizon-watch.config", "version": 0, "applications": []}', 'This file does not say which format version it uses.'],
    'a newer version' => ['{"format": "horizon-watch.config", "version": 2, "applications": []}', 'This file comes from a newer Horizon Watch: update the panel first.'],
    'no applications' => ['{"format": "horizon-watch.config", "version": 1}', 'This file lists its applications or alert rules in the wrong shape.'],
    'applications as an object' => ['{"format": "horizon-watch.config", "version": 1, "applications": {"name": "Acme Shop"}}', 'This file lists its applications or alert rules in the wrong shape.'],
    'alert rules as a string' => ['{"format": "horizon-watch.config", "version": 1, "applications": [], "alert_rules": "none"}', 'This file lists its applications or alert rules in the wrong shape.'],
]);

test('a file over 1 MB is refused before it is parsed', function () {
    $contents = json_encode(ConfigurationFiles::document(), JSON_THROW_ON_ERROR).str_repeat(' ', ConfigurationFormat::MAX_BYTES);

    expect(fn () => app(ConfigurationReader::class)->read($contents))
        ->toThrow(InvalidConfigurationFile::class, 'This file is larger than 1 MB.');
});

test('an older file is upgraded one version at a time up to the current one', function () {
    $step = fn (string $mark): FormatUpgrade => new class($mark) implements FormatUpgrade
    {
        public function __construct(private string $mark) {}

        public function upgrade(array $document): array
        {
            $document['steps'][] = $this->mark.' from '.$document['version'];

            return $document;
        }
    };

    $reader = new ConfigurationReader(currentVersion: 3, upgrades: [1 => $step('first'), 2 => $step('second')]);

    $document = $reader->read(json_encode(ConfigurationFiles::document(), JSON_THROW_ON_ERROR));

    expect($document->sourceVersion)->toBe(1)
        ->and($document->data['version'])->toBe(3)
        ->and($document->data['steps'])->toBe(['first from 1', 'second from 2']);
});

test('a stored document comes back the way it was kept', function () {
    $document = ConfigurationFiles::read(ConfigurationFiles::document([['name' => 'Acme Shop', 'environments' => []]]));

    expect(ConfigurationDocument::fromArray($document->toArray()))->toEqual($document)
        ->and(ConfigurationDocument::fromArray(['data' => 'broken']))->toBeNull();
});
