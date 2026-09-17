<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            update environment_states as state
            set status_since = coalesce((
                select min(run.captured_at)
                from environment_snapshots as run
                where run.environment_id = state.environment_id
                  and run.captured_at <= state.captured_at
                  and run.captured_at > coalesce((
                      select max(other.captured_at)
                      from environment_snapshots as other
                      where other.environment_id = state.environment_id
                        and other.captured_at <= state.captured_at
                        and other.status <> state.status
                  ), '-infinity'::timestamptz)
            ), state.captured_at)
            where state.status_since is null
            SQL);
    }

    public function down(): void {}
};
