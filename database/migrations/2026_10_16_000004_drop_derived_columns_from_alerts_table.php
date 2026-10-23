<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('alerts')
            ->where('notified', true)
            ->whereNull('last_notified_at')
            ->update(['last_notified_at' => DB::raw('opened_at')]);

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['notified', 'unit']);
        });
    }

    public function down(): void
    {
        // Rows rewritten in this transaction queue the deferred maintenance window
        // check, and Postgres alters no table with trigger events still pending.
        DB::statement('set constraints alerts_maintenance_window_id_foreign immediate');

        Schema::table('alerts', function (Blueprint $table) {
            $table->boolean('notified')->default(false);
            $table->string('unit', 8)->nullable();
        });

        DB::statement(<<<'SQL'
            update alerts set
                notified = (last_notified_at is not null),
                unit = case metric
                    when 'horizon.master_inactive' then 'min'
                    when 'endpoint.unreachable' then 'min'
                    when 'horizon.paused' then 'min'
                    when 'queue.pending' then 'job'
                    when 'jobs.failed_per_hour' then 'job'
                    when 'queue.max_wait' then 's'
                    when 'job.runtime' then 's'
                    else ''
                end
            SQL);

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('unit', 8)->nullable(false)->change();
        });
    }
};
