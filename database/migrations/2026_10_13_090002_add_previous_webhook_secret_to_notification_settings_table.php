<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->text('previous_webhook_secret')->nullable();
            $table->timestampTz('previous_webhook_secret_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn(['previous_webhook_secret', 'previous_webhook_secret_expires_at']);
        });
    }
};
