<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->index('environment_id');
        });

        Schema::table('environment_user', function (Blueprint $table) {
            $table->index('user_id');
        });

        DB::statement('create index alerts_resolved_at_index on alerts (resolved_at) where resolved_at is not null');
        DB::statement("create index alerts_digest_pending_index on alerts (team_id, opened_at) where severity = 'warning' and (digested_at is null or digested_at < resolved_at)");
        DB::statement('create index alerts_muted_by_index on alerts (muted_by) where muted_by is not null');
        DB::statement('create index alerts_handled_by_index on alerts (handled_by) where handled_by is not null');
        DB::statement('create index alert_notifications_orphan_sent_at_index on alert_notifications (sent_at) where alert_id is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists alert_notifications_orphan_sent_at_index');
        DB::statement('drop index if exists alerts_handled_by_index');
        DB::statement('drop index if exists alerts_muted_by_index');
        DB::statement('drop index if exists alerts_digest_pending_index');
        DB::statement('drop index if exists alerts_resolved_at_index');

        Schema::table('environment_user', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex(['environment_id']);
        });
    }
};
