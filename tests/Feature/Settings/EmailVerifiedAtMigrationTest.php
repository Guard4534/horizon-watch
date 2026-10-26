<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_16_000005_drop_email_verified_at_from_users_table.php');
});

test('a user row keeps no email verification time, since nothing verifies email', function () {
    expect(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();
});

test('downgrading restores the email verification time from the creation time', function () {
    $user = User::factory()->create(['created_at' => '2026-09-01 08:00:00']);

    $this->migration->down();

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBe('2026-09-01 08:00:00');

    $this->migration->up();

    expect(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();
});
