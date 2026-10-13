<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_snapshots', function (Blueprint $table) {
            $table->boolean('failed_last_hour_capped')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('environment_snapshots', function (Blueprint $table) {
            $table->dropColumn('failed_last_hour_capped');
        });
    }
};
