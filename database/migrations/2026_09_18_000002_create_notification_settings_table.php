<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->foreignId('team_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('recipients')->default('[]');
            $table->string('webhook_url', 2048)->nullable();
            $table->text('webhook_secret')->nullable();
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();
            $table->string('timezone', 64)->default('Europe/Rome');
            $table->unsignedSmallInteger('repeat_minutes')->nullable()->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
