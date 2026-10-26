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
            $table->unsignedBigInteger('maintenance_window_id')->nullable();
        });

        DB::statement('alter table alerts add constraint alerts_maintenance_window_id_foreign foreign key (maintenance_window_id) references maintenance_windows (id) on delete set null deferrable initially deferred');
        DB::statement('create index alerts_maintenance_window_id_index on alerts (maintenance_window_id) where maintenance_window_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists alerts_maintenance_window_id_index');

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign('alerts_maintenance_window_id_foreign');
            $table->dropColumn('maintenance_window_id');
        });
    }
};
