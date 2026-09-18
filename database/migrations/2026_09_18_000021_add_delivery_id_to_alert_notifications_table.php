<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->uuid('delivery_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('alert_notifications', function (Blueprint $table) {
            $table->dropColumn('delivery_id');
        });
    }
};
