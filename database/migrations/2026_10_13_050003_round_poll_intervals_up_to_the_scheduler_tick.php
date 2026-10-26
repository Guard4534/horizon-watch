<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('environments')
            ->whereRaw('poll_interval_seconds % 15 <> 0')
            ->update(['poll_interval_seconds' => DB::raw('ceil(poll_interval_seconds / 15.0) * 15')]);
    }

    public function down(): void {}
};
