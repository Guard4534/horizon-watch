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
            $table->timestampTz('resolution_notified_at')->nullable();
        });

        DB::table('alerts')
            ->whereNotNull('resolved_at')
            ->where('notified', true)
            ->update(['resolution_notified_at' => DB::raw('resolved_at')]);
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('resolution_notified_at');
        });
    }
};
