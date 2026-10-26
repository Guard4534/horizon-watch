<?php

use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->previousPgtz = getenv('PGTZ');
    putenv('PGTZ=Pacific/Auckland');

    config(['database.connections.pgsql_foreign_zone' => config('database.connections.pgsql')]);
});

afterEach(function () {
    DB::purge('pgsql_foreign_zone');
    putenv($this->previousPgtz === false ? 'PGTZ' : 'PGTZ='.$this->previousPgtz);
});

test('the connection talks UTC whatever the server session would default to', function () {
    $connection = DB::connection('pgsql_foreign_zone');

    expect($connection->selectOne("select current_setting('TimeZone') as zone")->zone)->toBe('UTC');
});

test('a captured_at written and read back is the same instant when the server session is not UTC', function () {
    $connection = DB::connection('pgsql_foreign_zone');
    $capturedAt = CarbonImmutable::parse('2026-09-17 10:00:00', 'UTC');

    $model = (new EnvironmentSnapshot)->setConnection('pgsql_foreign_zone');
    $stored = $connection->selectOne('select ?::timestamptz as captured_at, extract(epoch from ?::timestamptz)::bigint as epoch', [
        $model->fromDateTime($capturedAt),
        $model->fromDateTime($capturedAt),
    ]);

    $model->setRawAttributes(['captured_at' => $stored->captured_at]);

    expect((int) $stored->epoch)->toBe($capturedAt->getTimestamp())
        ->and($model->captured_at->getTimestamp())->toBe($capturedAt->getTimestamp());
});

test('every scheduling and invitation time is stored as an instant with its time zone', function (string $table, string $column) {
    $type = DB::selectOne('select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?', [$table, $column])->data_type;

    expect($type)->toBe('timestamp with time zone');
})->with([
    ['environments', 'last_polled_at'],
    ['environments', 'next_poll_at'],
    ['team_invitations', 'expires_at'],
    ['team_invitations', 'accepted_at'],
    ['team_invitations', 'revoked_at'],
]);

test('a next poll written while the server session is not UTC is due at the same instant', function () {
    $environment = Environment::factory()->create();
    $dueAt = CarbonImmutable::parse('2026-09-17 10:00:00', 'UTC');

    DB::statement("set local time zone 'Pacific/Auckland'");
    DB::table('environments')->where('id', $environment->id)->update(['next_poll_at' => $dueAt->setTimezone('Pacific/Auckland')->toIso8601String()]);

    expect($environment->fresh()->next_poll_at->getTimestamp())->toBe($dueAt->getTimestamp());
});

test('postgresql is the only database the panel is configured for', function () {
    $connection = getenv('DB_CONNECTION');
    $env = $_ENV['DB_CONNECTION'] ?? null;
    $server = $_SERVER['DB_CONNECTION'] ?? null;

    putenv('DB_CONNECTION');
    unset($_ENV['DB_CONNECTION'], $_SERVER['DB_CONNECTION']);

    try {
        $database = require config_path('database.php');
        $queue = require config_path('queue.php');
    } finally {
        if ($connection !== false) {
            putenv('DB_CONNECTION='.$connection);
        }

        if ($env !== null) {
            $_ENV['DB_CONNECTION'] = $env;
        }

        if ($server !== null) {
            $_SERVER['DB_CONNECTION'] = $server;
        }
    }

    expect(array_keys($database['connections']))->toBe(['pgsql'])
        ->and($database['default'])->toBe('pgsql')
        ->and($queue['failed']['database'])->toBe('pgsql')
        ->and($queue['batching']['database'])->toBe('pgsql');
});
