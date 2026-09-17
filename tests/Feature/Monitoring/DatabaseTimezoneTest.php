<?php

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
