<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('http_status')->nullable();

            $table->index(['alert_id', 'channel', 'target']);
        });
    }

    public function down(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dropIndex(['alert_id', 'channel', 'target']);
            $table->dropColumn('http_status');
        });
    }
};
