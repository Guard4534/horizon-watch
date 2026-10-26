<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update environment_states
            set last_known_status = status,
                last_known_since = status_since,
                last_known_at = captured_at
            where status <> 'unreachable'
              and last_known_status is null
            SQL);
    }

    public function down(): void {}
};
