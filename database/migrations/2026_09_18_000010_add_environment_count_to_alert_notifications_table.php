<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('environment_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dropColumn('environment_count');
        });
    }
};
